# PsyncLib

PsyncLib (`net.nosial.psynclib`) is a PHP library that introduces parallel operations into PHP without the need for
additional software such as a Message Queue or a Job Queue. Using Shared Memory & Forking, PsyncLib allows you to
create parallel operations by calling several simple functions.

## Table of contents

<!-- TOC -->
* [PsyncLib](#psynclib)
  * [Table of contents](#table-of-contents)
  * [Versioning](#versioning)
  * [Installation](#installation)
  * [Usage](#usage)
  * [Classes](#classes)
    * [P](#p)
    * [Psync](#psync)
      * [public static function do(callable $callable, array $args = []): P](#public-static-function-docallable-callable-array-args---p)
      * [public static function isDone(P $p): bool](#public-static-function-isdonep-p-bool)
      * [public static function waitFor(P $p): mixed](#public-static-function-waitforp-p-mixed)
      * [private static function close(P $p): void](#private-static-function-closep-p-void)
      * [public static function wait(): array](#public-static-function-wait-array)
      * [public static function total(): int](#public-static-function-total-int)
      * [public static function running(): int](#public-static-function-running-int)
      * [public static function clean(): int](#public-static-function-clean-int)
      * [public static function getSharedMemorySize(): int](#public-static-function-getsharedmemorysize-int)
      * [public static function setSharedMemorySize(int $sharedMemorySize): void](#public-static-function-setsharedmemorysizeint-sharedmemorysize-void)
      * [public static function getSharedMemoryPermissions(): int](#public-static-function-getsharedmemorypermissions-int)
      * [public static function setSharedMemoryPermissions(int $sharedMemoryPermissions): void](#public-static-function-setsharedmemorypermissionsint-sharedmemorypermissions-void)
  * [License](#license)
  * [Contributing](#contributing)
<!-- TOC -->


## Versioning

This library uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


## Installation

To install PsyncLib, you can use [ncc](https://git.n64.cc/nosial/ncc). (can also be found at [github](https://github.com/nosial/ncc))
Run the following command:

```bash
ncc package install -p="nosial/libs.psync=latest@n64"
```

To add the package as a dependency to your project, add this entry under `build{} -> dependencies[]` in your
`project.json` file:

```json
{
  "name": "net.nosial.psynclib",
  "version": "latest",
  "source_type": "remote",
  "source": "nosial/libs.psync=latest@n64"
}
```

The GitHub alternative name is `nosial/psynclib=latest@github` if you prefer to use GitHub as the source.


## Usage

Psync is called under the namespace `\PsyncLib\Psync`. The following is an example of how to use Psync to calculate
the value of Pi using the Monte Carlo method in parallel using 4 processes, this will display the value of Pi and the
time taken to calculate it per process and finally the total time in total the script took to run.

```php
<?php
    // Require ncc, this is required to load the PsyncLib package
    require 'ncc';

    // Import the Psync package (Not needed if the project references the package as a dependency)
    import('net.nosial.psynclib');

    // Begin the fun
    $parentStart = microtime(true);

    $promises = [];
    for($i = 0; $i < 10; $i++) {
        $promises[] = \PsyncLib\Psync::do(function() {
            $start = microtime(true);
            $pi = 0;
            $n = 1000000;
            for($i = 1; $i < $n; $i++) {
                $pi += 4 * (pow(-1, $i + 1) / (2 * $i - 1));
            }
            $end = microtime(true);
            return ['result' => $pi, 'time' => $end - $start];
        });
    }

    // Wait for all promises to resolve
    /** @var \PsyncLib\P $p */
    foreach($promises as $p) {
        $result = \PsyncLib\Psync::waitFor($p);
        printf("Result: %f, Time: %f\n", $result['result'], $result['time']);
    }

    $parentEnd = microtime(true);
    printf("Parent Time: %f\n", $parentEnd - $parentStart);
```

Running this on a test machine, the output will be similar to the following:

```
Result: 3.141594, Time: 2.113697
Result: 3.141594, Time: 2.370120
Result: 3.141594, Time: 2.043246
Result: 3.141594, Time: 2.124459
Result: 3.141594, Time: 2.245291
Result: 3.141594, Time: 2.309868
Result: 3.141594, Time: 2.163053
Result: 3.141594, Time: 2.375659
Result: 3.141594, Time: 2.360129
Result: 3.141594, Time: 2.006329
Parent Time: 2.390980
```


## Classes

Psync is a very lightweight library, it only has 2 classes:

### P

The `P` class is a simple class that represents a promise, it is used to store the state of the promise, the object
itself should be treated as a reference, it has contains no actual functionality.

 - `public function getUuid(): string` - Returns the UUID of the promise
 - `public function getPid(): int` - Returns the PID of the process
 - `public function getShm(): Shmop` - Returns the Shared Memory object
 - `public function __toString(): string` - Returns the UUID of the promise


### Psync

The `Psync` class is the main class used to interact with the Psync Library


#### public static function do(callable $callable, array $args = []): P

This method creates a new promise by forking a process to execute the given callable function.
The result of the callable is stored in shared memory and can be retrieved later.

- **Parameters:**
  - `callable $callable`: The function to be executed in the forked process.
  - `array $args`: An optional array of arguments to pass to the callable.

- **Returns:**
  - `P`: An instance of the `P` class representing the promise.

- **Throws:**
  - `RuntimeException`: If the shared memory segment or the process fork fails.

Usage Example:

```php
<?php
    $p = \PsyncLib\Psync::do(function() {
        return 'Hello, World!';
    });

    // Wait for the promise to resolve
    $result = \PsyncLib\Psync::waitFor($p);
    echo $result; // Output: Hello, World!
```


#### public static function isDone(P $p): bool

This method checks if the given promise has completed its execution.

- **Parameters:**
  - `P $p`: The promise instance to check.

- **Returns:**
  - `bool`: `true` if the promise has completed, `false` otherwise.

- **Throws:**
  - `RuntimeException`: If there is an error while checking the process status.

Usage Example:

```php
<?php
    $p = \PsyncLib\Psync::do(function() {
        return 'Hello, World!';
    });

    // Check if the promise has completed
    if (\PsyncLib\Psync::isDone($p)) {
        echo 'Promise is done!';
    } else {
        echo 'Promise is still running...';
    }
```


#### public static function waitFor(P $p): mixed

This method waits for the given promise to complete and retrieves the result stored in shared memory.

- **Parameters:**
  - `P $p`: The promise instance containing details about the process to wait for.

- **Returns:**
  - `mixed`: The result retrieved from the shared memory, which may throw an exception if an error occurred within the process.

- **Throws:**
  - `Throwable`: If the result is an exception, it will be thrown.

Usage Example:

```php
<?php
    $p = \PsyncLib\Psync::do(function() {
        return 'Hello, World!';
    });

    // Wait for the promise to resolve
    $result = \PsyncLib\Psync::waitFor($p);
    echo $result; // Output: Hello, World!
```


#### private static function close(P $p): void

This method closes and cleans up resources associated with the given process.

- **Parameters:**
  - `P $p`: The process instance to be closed.

- **Returns:**
  - `void`

Usage Example:

```php
<?php
    $p = \PsyncLib\Psync::do(function() {
        return 'Hello, World!';
    });

    // Close the process and clean up resources
    \PsyncLib\Psync::close($p);
```


#### public static function wait(): array

This method waits for the completion of all promises and returns their results.

- **Returns:**
  - `array`: An associative array containing the results of each promise, indexed by their unique identifiers (UUIDs).

- **Throws:**
  - `Throwable`: If any of the promises throw an exception, it will be thrown.

Usage Example:

```php
<?php
    $promises = [];
    for($i = 0; $i < 10; $i++) {
        $promises[] = \PsyncLib\Psync::do(function() {
            return 'Hello, World!';
        });
    }

    // Wait for all promises to resolve
    $results = \PsyncLib\Psync::wait();
    foreach($results as $uuid => $result) {
        echo "UUID: $uuid, Result: $result\n";
    }
```


#### public static function total(): int

This method calculates the total number of promises.

- **Returns:**
  - `int`: The total number of promises.

Usage Example:

```php
<?php
    $totalPromises = \PsyncLib\Psync::total();
    echo "Total Promises: $totalPromises\n";
```


#### public static function running(): int

This method counts and returns the number of promises that are currently running.

- **Returns:**
  - `int`: The number of running promises.

Usage Example:

```php
<?php
    $runningPromises = \PsyncLib\Psync::running();
    echo "Running Promises: $runningPromises\n";
```


#### public static function clean(): int

This method cleans up resources associated with promises that are not completed.

Iterates through a collection of promises, and for each promise that is not yet marked as done, calls a method to close and clean up the resource.

- **Returns:**
  - `int`: The number of promises that were closed and cleaned up.

Usage Example:

```php
<?php
    $cleanedPromises = \PsyncLib\Psync::clean();
    echo "Cleaned Promises: $cleanedPromises\n";
```


#### public static function getSharedMemorySize(): int

This method returns the size of the shared memory.

- **Returns:**
  - `int`: The size of the shared memory.

Usage Example:

```php
<?php
    $sharedMemorySize = \PsyncLib\Psync::getSharedMemorySize();
    echo "Shared Memory Size: $sharedMemorySize\n";
```


#### public static function setSharedMemorySize(int $sharedMemorySize): void

This method sets the size of the shared memory.

- **Parameters:**
  - `int $sharedMemorySize`: The new size for the shared memory.

- **Returns:**
  - `void`

Usage Example:

```php
<?php
    \PsyncLib\Psync::setSharedMemorySize(1024);
    echo "Shared Memory Size set to 1024\n";
```


#### public static function getSharedMemoryPermissions(): int

This method returns the permissions of the shared memory.

- **Returns:**
  - `int`: The permissions of the shared memory.

Usage Example:

```php
<?php
    $sharedMemoryPermissions = \PsyncLib\Psync::getSharedMemoryPermissions();
    echo "Shared Memory Permissions: $sharedMemoryPermissions\n";
```


#### public static function setSharedMemoryPermissions(int $sharedMemoryPermissions): void

This method sets the permissions for the shared memory.

- **Parameters:**
  - `int $sharedMemoryPermissions`: The permissions to be set for the shared memory.

- **Returns:**
  - `void`

Usage Example:

```php
<?php
    \PsyncLib\Psync::setSharedMemoryPermissions(0666);
    echo "Shared Memory Permissions set to 0666\n";
```


## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details

## Contributing

Feel free to contribute to this project by submitting a pull request or opening an issue.