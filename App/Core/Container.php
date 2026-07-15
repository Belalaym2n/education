<?php
// Container.php
 namespace App\Core;


use App\Exceptions\AppException;
use Throwable;

class Container {
    private array $bindings = [];

    public function bind(
        string $abstract, callable 
        $factory): void {
        $this->bindings[$abstract] = $factory;
    }
 
    public function singleton(string $abstract, callable $factory): void {
        $this->bindings[$abstract] = function 
        () use ($factory) {
            static $instance = null;
            if ($instance === null) {
                $instance = $factory($this);
            }
            return $instance;
        };  
    }

    public function make(string $abstract) {
        if (!isset($this->bindings[$abstract])) {
            throw new AppException("No binding found for $abstract");
        }
        return $this->bindings[$abstract]($this);
    }    public function getBindings()
    {
        return array_keys($this->bindings);
    }
} 