<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry;

use Mcp\Exception\ContainerException;
use Mcp\Exception\ServiceNotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * A basic PSR-11 container implementation with simple constructor auto-wiring.
 *
 * Supports instantiating classes with parameterless constructors or constructors
 * where all parameters are type-hinted classes/interfaces known to the container,
 * or have default values. Does NOT support scalar/built-in type injection without defaults.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class Container implements ContainerInterface
{
    /**
     * @var array<string, object> Cache for already created instances (shared singletons)
     */
    private array $instances = [];

    /**
     * @var array<string, bool> Track classes currently being resolved to detect circular dependencies
     */
    private array $resolving = [];

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id identifier of the entry to look for (usually a FQCN)
     *
     * @return mixed entry
     *
     * @throws NotFoundExceptionInterface  no entry was found for **this** identifier
     * @throws ContainerExceptionInterface Error while retrieving the entry (e.g., dependency resolution failure, circular dependency).
     */
    public function get(string $id): mixed
    {
        // Check instance cache
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // Check if class exists
        if (!class_exists($id) && !interface_exists($id)) { // Also check interface for bindings
            throw new ServiceNotFoundException(\sprintf('Class, interface, or entry "%s" not found.', $id));
        }

        // Circular dependency check
        if (isset($this->resolving[$id])) {
            throw new ContainerException("Circular dependency detected while resolving '{$id}'. Resolution path: ".implode(' -> ', array_keys($this->resolving))." -> {$id}");
        }

        $this->resolving[$id] = true; // Mark as currently resolving

        try {
            // Reflect on the class
            $reflector = new \ReflectionClass($id);

            // Check if class is instantiable (abstract classes, interfaces cannot be directly instantiated)
            if (!$reflector->isInstantiable()) {
                // We might have an interface bound to a concrete class via set()
                // This check is slightly redundant due to class_exists but good practice
                throw new ContainerException("Class '{$id}' is not instantiable (e.g., abstract class or interface without explicit binding).");
            }

            // Get the constructor
            $constructor = $reflector->getConstructor();

            // If no constructor or constructor has no parameters, instantiate directly
            if (null === $constructor || 0 === $constructor->getNumberOfParameters()) {
                $instance = $reflector->newInstance();
            } else {
                // Constructor has parameters, attempt to resolve them
                $parameters = $constructor->getParameters();
                $resolvedArgs = [];

                foreach ($parameters as $parameter) {
                    $resolvedArgs[] = $this->resolveParameter($parameter, $id);
                }

                // Instantiate with resolved arguments
                $instance = $reflector->newInstanceArgs($resolvedArgs);
            }

            // Cache the instance
            $this->instances[$id] = $instance;

            return $instance;
        } catch (\ReflectionException $e) {
            throw new ContainerException(\sprintf('Reflection failed for %s.', $id), 0, $e);
        } catch (ContainerExceptionInterface $e) { // Re-throw container exceptions directly
            throw $e;
        } catch (\Throwable $e) { // Catch other instantiation errors
            throw new ContainerException("Failed to instantiate or resolve dependencies for '{$id}': ".$e->getMessage(), (int) $e->getCode(), $e);
        } finally {
            // Remove from resolving stack once done (success or failure)
            unset($this->resolving[$id]);
        }
    }

    /**
     * Attempts to resolve a single constructor parameter.
     *
     * @throws ContainerExceptionInterface if a required dependency cannot be resolved
     */
    private function resolveParameter(\ReflectionParameter $parameter, string $consumerClassId): mixed
    {
        // Check for type hint
        $type = $parameter->getType();

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            // Type hint is a class or interface name
            $typeName = $type->getName();
            try {
                // Recursively get the dependency
                return $this->get($typeName);
            } catch (NotFoundExceptionInterface $e) {
                // Dependency class not found, fail ONLY if required
                if (!$parameter->isOptional() && !$parameter->allowsNull()) {
                    throw new ContainerException("Unresolvable dependency '{$typeName}' required by '{$consumerClassId}' constructor parameter \${$parameter->getName()}.", 0, $e);
                }
                // If optional or nullable, proceed (will check allowsNull/Default below)
            } catch (ContainerExceptionInterface $e) {
                // Dependency itself failed to resolve (e.g., its own deps, circular)
                throw new ContainerException("Failed to resolve dependency '{$typeName}' for '{$consumerClassId}' parameter \${$parameter->getName()}: ".$e->getMessage(), 0, $e);
            }
        }

        // Check if parameter has a default value
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        // Check if parameter allows null (and wasn't resolved above)
        if ($parameter->allowsNull()) {
            return null;
        }

        // Check if it was a built-in type without a default (unresolvable by this basic container)
        if ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
            throw new ContainerException("Cannot auto-wire built-in type '{$type->getName()}' for required parameter \${$parameter->getName()} in '{$consumerClassId}' constructor. Provide a default value or use a more advanced container.");
        }

        // Check if it was a union/intersection type without a default (also unresolvable)
        if (null !== $type && !$type instanceof \ReflectionNamedType) {
            throw new ContainerException("Cannot auto-wire complex type (union/intersection) for required parameter \${$parameter->getName()} in '{$consumerClassId}' constructor. Provide a default value or use a more advanced container.");
        }

        // If we reach here, it's an untyped, required parameter without a default.
        // Or potentially an unresolvable optional class dependency where null is not allowed (edge case).
        throw new ContainerException("Cannot resolve required parameter \${$parameter->getName()} for '{$consumerClassId}' constructor (untyped or unresolvable complex type).");
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     *
     * Mirrors what get() can actually do: explicitly set instances, or classes
     * whose constructor parameters are all auto-wirable.
     */
    public function has(string $id): bool
    {
        if (isset($this->instances[$id])) {
            return true;
        }

        if (!class_exists($id)) {
            return false;
        }

        return $this->canAutowire($id, []);
    }

    /**
     * Checks whether get() would be able to build the given class, without instantiating it.
     *
     * @param array<string, true> $resolving classes currently being checked, to break circular dependencies
     */
    private function canAutowire(string $className, array $resolving): bool
    {
        if (isset($resolving[$className])) {
            return false;
        }
        $resolving[$className] = true;

        try {
            $reflector = new \ReflectionClass($className);
        } catch (\ReflectionException) {
            return false;
        }

        if (!$reflector->isInstantiable()) {
            return false;
        }

        $constructor = $reflector->getConstructor();
        if (null === $constructor) {
            return true;
        }

        foreach ($constructor->getParameters() as $parameter) {
            if (!$this->canResolveParameter($parameter, $resolving)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mirrors resolveParameter(): true if the parameter could be resolved without throwing.
     *
     * @param array<string, true> $resolving
     */
    private function canResolveParameter(\ReflectionParameter $parameter, array $resolving): bool
    {
        $type = $parameter->getType();

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();

            if (isset($this->instances[$typeName])) {
                return true;
            }

            if (class_exists($typeName) || interface_exists($typeName)) {
                // resolveParameter() delegates to get(), which must succeed
                return $this->canAutowire($typeName, $resolving);
            }

            // Unknown dependency is tolerated only for optional or nullable parameters
            if (!$parameter->isOptional() && !$parameter->allowsNull()) {
                return false;
            }
        }

        return $parameter->isDefaultValueAvailable() || $parameter->allowsNull();
    }

    /**
     * Adds a pre-built instance or a factory/binding to the container.
     * This basic version only supports pre-built instances (singletons).
     */
    public function set(string $id, object $instance): void
    {
        // Could add support for closures/factories later if needed
        $this->instances[$id] = $instance;
    }
}
