<?php

    namespace PsyncLib;

    use RuntimeException;
    use Throwable;

    class Psync
    {
        private static int $sharedMemorySize = 1024;
        private static int $sharedMemoryPermissions = 0644;
        private static array $promises = [];

        /**
         * Executes a callable within a forked process while handling
         * inter-process communication via shared memory.
         *
         * @param callable $callable The function to be executed in the child process.
         * @param array $args Optional. The arguments to pass to the callable. Defaults to an empty array.
         * @return P Returns an instance of P representing the state and management of the forked process.
         * @throws RuntimeException If it fails to create a shared memory segment or fork the process.
         */
        public static function do(callable $callable, array $args = []): P
        {
            $shm_key = ftok(__FILE__, chr(mt_rand(0, 255))); // Generate a more unique key
            $try = 0;
            $shm = false;

            // Handle potential conflicts, limit retry to a reasonable amount
            while ($shm === false && $try < 10)
            {
                $shm = @shmop_open($shm_key, 'c', self::$sharedMemoryPermissions, self::$sharedMemorySize); // Suppress errors, open shared memory segment
                if ($shm === false)
                {
                    $shm_key = ftok(__FILE__, chr(mt_rand(0, 255))); // Regenerate key if creation fails
                    $try++;
                }
            }

            if ($shm === false)
            {
                throw new RuntimeException("Failed to create shared memory segment.");
            }

            $pid = pcntl_fork(); // Fork the process
            if ($pid == -1)
            {
                throw new RuntimeException("Failed to fork process.");
            }
            elseif ($pid === 0)
            {
                // Child process
                try
                {
                    $result = call_user_func_array($callable, $args); // Execute the callable
                    $serialized = serialize($result); // Serialize the result
                    // Write the length of the serialized data and the data itself
                    $data = pack('L', strlen($serialized)) . $serialized; // Pack the length as a 4-byte integer
                    shmop_write($shm, $data, 0); // Write to shared memory
                }
                catch (Throwable $e)
                {
                    $error = serialize($e); // Serialize exception if any
                    $data = pack('L', strlen($error)) . $error; // Pack the length as a 4-byte integer
                    shmop_write($shm, $data, 0);
                }
                finally
                {
                    shmop_delete($shm); // Delete shared memory
                    exit(0); // Exit the child process
                }
            }

            // Parent process: return the P object immediately
            $p = new P($pid, $shm);
            self::$promises[$p->getUuid()] = $p;
            return $p;
        }

        /**
         * Checks if the process is completed.
         *
         * @param P $p The process instance to check.
         * @return bool True if the process is done, false otherwise.
         */
        public static function isDone(P $p): bool
        {
            $status = 0;
            $pid = pcntl_waitpid($p->getPid(), $status, WNOHANG);
            return $pid === -1 || $pid > 0;
        }

        /**
         * Waits for a process to finish and retrieves the result stored in shared memory.
         *
         * @param P $p The process instance containing details about the process to wait for.
         * @return mixed The result retrieved from the shared memory, which may throw an exception if an error occurred within the process.
         * @throws Throwable If the result is an exception, it will be thrown.
         */
        public static function waitFor(P $p): mixed
        {
            // Wait for the process to finish
            pcntl_waitpid($p->getPid(), $status);

            // Read the serialized data from shared memory
            $shm = $p->getShm();
            $data = shmop_read($shm, 0, shmop_size($shm));

            // Extract the length of the serialized data
            $length = unpack('L', substr($data, 0, 4))[1];
            $serialized = substr($data, 4, $length); // Read only the relevant serialized part

            // Clean up the shared memory
            shmop_delete($shm);
            unset(self::$promises[$p->getUuid()]);

            // Unserialize the data
            $result = unserialize($serialized);

            // Check if the result was an exception
            if ($result instanceof Throwable)
            {
                throw $result;
            }

            return $result;
        }

        /**
         * Waits for the completion of all promises and returns their results.
         *
         * @return array An associative array containing the results of each promise,
         *               indexed by their unique identifiers (UUIDs).
         */
        public static function wait(): array
        {
            $results = [];

            while (count(self::$promises) > 0)
            {
                foreach (self::$promises as $uuid => $p)
                {
                    $results[$uuid] = self::waitFor($p);
                }
            }

            return $results;
        }

        /**
         * Calculates the total number of promises.
         *
         * @return int The total number of promises.
         */
        public static function total(): int
        {
            return count(self::$promises);
        }

        /**
         * Counts and returns the number of promises that are currently running.
         *
         * @return int The number of running promises.
         */
        public static function running(): int
        {
            $count = 0;

            foreach(self::$promises as $uuid => $p)
            {
                if(!self::isDone($p))
                {
                    $count++;
                }
            }

            return $count;
        }

        /**
         * Returns the size of the shared memory.
         *
         * @return int The size of the shared memory.
         */
        public static function getSharedMemorySize(): int
        {
            return self::$sharedMemorySize;
        }

        /**
         * Sets the size of the shared memory.
         *
         * @param int $sharedMemorySize The new size for the shared memory.
         * @return void
         */
        public static function setSharedMemorySize(int $sharedMemorySize): void
        {
            self::$sharedMemorySize = $sharedMemorySize;
        }

        /**
         * Returns the permissions of the shared memory.
         *
         * @return int The permissions of the shared memory.
         */
        public static function getSharedMemoryPermissions(): int
        {
            return self::$sharedMemoryPermissions;
        }

        /**
         * Sets the permissions for the shared memory.
         *
         * @param int $sharedMemoryPermissions The permissions to be set for the shared memory.
         * @return void
         */
        public static function setSharedMemoryPermissions(int $sharedMemoryPermissions): void
        {
            self::$sharedMemoryPermissions = $sharedMemoryPermissions;
        }
    }
