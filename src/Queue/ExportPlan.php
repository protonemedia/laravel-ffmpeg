<?php

namespace ProtoneMedia\LaravelFFMpeg\Queue;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ReflectionClass;
use ReflectionFunction;
use ReflectionProperty;
use SplObjectStorage;
use Symfony\Component\HttpFoundation\File\File;
use Throwable;

/**
 * The captured state of an export builder that a queue worker restores.
 * It holds trusted builder state, never request-supplied serialized data.
 *
 * @internal
 *
 * @template T of Exportable
 */
class ExportPlan
{
    /**
     * The encoded builder state.
     */
    protected readonly array $state;

    /**
     * Create a new export plan instance.
     *
     * @param  T  $export
     */
    public function __construct(Exportable $export)
    {
        $this->state = $this->encode($export, new SplObjectStorage);
    }

    /**
     * Restore a fresh copy of the captured export builder.
     *
     * @return T
     */
    public function restore(): Exportable
    {
        $objects = [];

        return $this->decode($this->state, $objects);
    }

    /**
     * Encode the given value into serializable plan state.
     */
    protected function encode(mixed $value, SplObjectStorage $objects): mixed
    {
        if (is_resource($value)
            || $value instanceof Filesystem
            || $value instanceof File) {
            throw new InvalidArgumentException(
                'Queued exports require durable named disks, not filesystem objects, uploads, local File inputs, or open streams.'
            );
        }

        if (is_array($value)) {
            return [
                'type' => 'array',
                'items' => array_map(fn ($item): mixed => $this->encode($item, $objects), $value),
            ];
        }

        if ($value instanceof Closure) {
            return $this->encodeClosure($value);
        }

        if (! is_object($value)) {
            return $value;
        }

        // We only capture the package's own builders and Laravel's image transformations, which
        // are scalar value objects that extend nothing. A workspace is a runtime service.
        if ((! str_starts_with($value::class, 'ProtoneMedia\\LaravelFFMpeg\\')
            && ! str_starts_with($value::class, 'Illuminate\\Image\\Transformations\\'))
            || $value instanceof Workspace) {
            throw new InvalidArgumentException(
                'Queued export plans accept fresh native builders and serializable callbacks, not runtime services.'
            );
        }

        if ($objects->contains($value)) {
            return ['type' => 'reference', 'id' => $objects[$value]];
        }

        return $this->encodeObject($value, $objects);
    }

    /**
     * Encode the given static closure after ensuring it captures only scalar values or arrays.
     */
    protected function encodeClosure(Closure $value): array
    {
        $reflection = new ReflectionFunction($value);

        if ($reflection->getClosureThis() !== null) {
            throw new InvalidArgumentException(
                'Queued callbacks must be static closures capturing only scalar values or arrays.'
            );
        }

        $captures = $reflection->getStaticVariables();

        array_walk_recursive($captures, static function ($capture): void {
            if (is_object($capture) || is_resource($capture)) {
                throw new InvalidArgumentException('Queued callbacks must capture only scalar values or arrays.');
            }
        });

        try {
            return ['type' => 'closure', 'value' => serialize(new SerializableClosure($value))];
        } catch (Throwable) {
            throw new InvalidArgumentException(
                'Queue callbacks must be serializable. Use static closures capturing scalar identifiers instead of request or service objects.'
            );
        }
    }

    /**
     * Encode the given package object and every property it has initialized.
     */
    protected function encodeObject(object $value, SplObjectStorage $objects): array
    {
        $id = count($objects);
        $objects[$value] = $id;
        $properties = [];

        // Package classes extend nothing, so their own properties hold their entire state.
        foreach (new ReflectionClass($value)->getProperties() as $property) {
            if (! $property->isStatic() && $property->isInitialized($value)) {
                $properties[] = [$value::class, $property->name, $this->encode($property->getValue($value), $objects)];
            }
        }

        return ['type' => 'object', 'class' => $value::class, 'id' => $id, 'properties' => $properties];
    }

    /**
     * Decode the given plan state, restoring shared objects as shared references.
     */
    protected function decode(mixed $value, array &$objects): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value['type'] === 'array') {
            $result = [];

            foreach ($value['items'] as $key => $item) {
                $result[$key] = $this->decode($item, $objects);
            }

            return $result;
        }

        if ($value['type'] === 'closure') {
            return unserialize($value['value'])->getClosure();
        }

        if ($value['type'] === 'reference') {
            return $objects[$value['id']];
        }

        $object = new ReflectionClass($value['class'])->newInstanceWithoutConstructor();
        $objects[$value['id']] = $object;

        foreach ($value['properties'] as [$class, $name, $item]) {
            new ReflectionProperty($class, $name)->setValue($object, $this->decode($item, $objects));
        }

        return $object;
    }
}
