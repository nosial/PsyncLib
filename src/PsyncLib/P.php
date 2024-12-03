<?php

    namespace PsyncLib;

    use Shmop;
    use Symfony\Component\Uid\UuidV4;

    class P
    {
        private string $uuid;
        private int $pid;
        private Shmop $shm;

        /**
         * Constructor method for initializing an instance with process ID and shared memory ID.
         *
         * @param int $pid Process ID to be assigned.
         * @param Shmop $shm_id Shared memory ID to be associated.
         */
        public function __construct(int $pid, Shmop $shm_id)
        {
            $this->uuid = (new UuidV4())->toRfc4122();
            $this->pid = $pid;
            $this->shm = $shm_id;
        }

        /**
         * Retrieves the universally unique identifier (UUID).
         *
         * @return string The UUID associated with this instance.
         */
        public function getUuid(): string
        {
            return $this->uuid;
        }

        /**
         * Retrieves the process identifier (PID).
         *
         * @return int The PID associated with this instance.
         */
        public function getPid(): int
        {
            return $this->pid;
        }

        /**
         * Retrieves the shared memory block.
         *
         * @return Shmop The shared memory block associated with this instance.
         */
        public function getShm(): Shmop
        {
            return $this->shm;
        }

        /**
         *
         */
        public function __toString(): string
        {
            return $this->uuid;
        }

        /** @noinspection PhpConditionAlreadyCheckedInspection */
        /**
         * Destructor method that ensures the shared memory is closed when the object is destroyed.
         *
         * @return void
         */
        public function __destruct()
        {
            // Ensure the shared memory is closed when the object is destroyed
            if (is_resource($this->shm))
            {
                shmop_delete($this->shm);
            }
        }
    }
