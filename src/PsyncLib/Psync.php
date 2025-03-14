<?php

    namespace PsyncLib;

    use LogLib2\Logger;
    use RuntimeException;
    use Throwable;

    class Psync
    {
        private static ?Logger $logger = null;
        private static int $sharedMemorySize = 65536;
        private static int $sharedMemoryPermissions = 0644;
        private static array $promises = [];
        private static int $lastClean = 0;

        /**
         * Retrieves the singleton instance of the Logger.
         *
         * Checks if the static $logger property is null, and if so, initializes it with
         * a new Logger instance configured with a specific name. Ensures that the same
         * Logger instance is returned on subsequent calls.
         *
         * @return Logger The singleton Logger instance for logging activities.
         */
        private static function getLogger(): Logger
        {
            if(self::$logger === null)
            {
                self::$logger = new Logger('net.nosial.psync');
            }

            return self::$logger;
        }

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
            self::getLogger()->debug(sprintf('[%s]: Preparing to call %s', posix_getpid(), self::callableToString($callable)));
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
                self::getLogger()->debug(sprintf('[%s]: Executing %s', posix_getpid(), self::callableToString($callable)));
                // Child process
                try
                {
                    $result = call_user_func_array($callable, $args); // Execute the callable
                    $serialized = serialize($result); // Serialize the result
                    // Write the length of the serialized data and the data itself
                    $data = pack('L', strlen($serialized)) . $serialized; // Pack the length as a 4-byte integer
                    shmop_write($shm, $data, 0); // Write to shared memory
                    self::getLogger()->debug(sprintf('[%s]: Finished executing %s', posix_getpid(), self::callableToString($callable)));
                }
                catch (Throwable $e)
                {
                    self::getLogger()->error(sprintf('[%s]: Exception thrown for %s: %s', posix_getpid(), self::callableToString($callable), $e->getMessage()), $e);
                    $error = serialize($e); // Serialize exception if any
                    $data = pack('L', strlen($error)) . $error; // Pack the length as a 4-byte integer
                    shmop_write($shm, $data, 0);
                }
                finally
                {
                    self::getLogger()->debug(sprintf('[%s]: Resource closure at %s', posix_getpid(), self::callableToString($callable)));
                    shmop_delete($shm); // Delete shared memory
                    exit(0); // Exit the child process
                }
            }

            // Parent process: return the P object immediately
            $p = new P($pid, $shm);
            self::getLogger()->debug(sprintf('[%s]: Promise created for %s: %s', posix_getpid(), self::callableToString($callable), "test"));
            self::$promises[$p->getUuid()] = $p;
            return $p;
        }

        /**
         * Converts a*/
        private static function callableToString(callable $callable): string
        {
            if(is_string($callable))
            {
                return $callable;
            }

            if(is_array($callable))
            {
                foreach($callable as $item)
                {
                    if(is_string($item))
                    {
                        return $item;
                    }
                }
            }

            return '';
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
            pcntl_signal(SIGTERM, SIG_DFL);
            posix_kill($p->getPid(), SIGTERM);

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
         * Closes and cleans up resources associated with the given process.
         *
         * @param P $p The process instance to be closed.
         * @return void
         */
        private static function close(P $p): void
        {
            // Clean up the child process to prevent zombie processes
            pcntl_waitpid($p->getPid(), $status);
            pcntl_signal(SIGTERM, SIG_DFL);
            posix_kill($p->getPid(), SIGTERM);

            $shm = $p->getShm();
            shmop_delete($shm);
            unset(self::$promises[$p->getUuid()]);
        }

        /**
         * Waits for the completion of all promises and returns their results.
         *
         * @return array An associative array containing the results of each promise,
         *               indexed by their unique identifiers (UUIDs).
         * @throws Throwable
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
         * Cleans up resources associated with promises that are not completed.
         *
         * Iterates through a collection of promises, and for each promise that is not
         * yet marked as done, calls a method to close and clean up the resource.
         *
         * @return int The number of promises that were closed and cleaned up.
         */
        public static function clean(): int
        {
            if(time() - self::$lastClean < 8)
            {
                return 0;
            }

            self::$lastClean = time();
            $count = 0;
            foreach(self::$promises as $uuid => $p)
            {
                if(!self::isDone($p))
                {
                    self::close($p);
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
