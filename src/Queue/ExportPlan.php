<?php

namespace ProtoneMedia\LaravelFFMpeg\Queue;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ReflectionClass;
use ReflectionFunction;
use SplObjectStorage;
use Throwable;

/**
 * @internal Trusted builder state, never request-supplied serialized data.
 *
 * @template T of Exportable
 */
final readonly class ExportPlan
{
    private array $state;

    /** @param T $export */
    public function __construct(Exportable $export)
    {
        $this->state = $this->encode($export, new SplObjectStorage);
    }

    /** @return T */
    public function restore(): Exportable
    {
        $objects = [];

        return $this->decode($this->state, $objects);
    }

    private function encode(mixed $value, SplObjectStorage $objects): mixed
    {
        if (is_resource($value) || $value instanceof Filesystem || $value instanceof File || $value instanceof \Symfony\Component\HttpFoundation\File\File) {
            throw new InvalidArgumentException('Queued exports require durable named disks, not filesystem objects, uploads, local File inputs, or open streams.');
        }

        if (is_array($value)) {
            return ['type' => 'array', 'items' => array_map(fn ($item): mixed => $this->encode($item, $objects), $value)];
        }

        if ($value instanceof Closure) {
            $reflection = new ReflectionFunction($value);
            if ($reflection->getClosureThis() !== null) {
                throw new InvalidArgumentException('Queued callbacks must be static closures capturing only scalar values or arrays.');
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
                throw new InvalidArgumentException('Queue callbacks must be serializable. Use static closures capturing scalar identifiers instead of request or service objects.');
            }
        }

        if (! is_object($value)) {
            return $value;
        }

        if (! str_starts_with($value::class, 'ProtoneMedia\\LaravelFFMpeg\\') || $value instanceof \ProtoneMedia\LaravelFFMpeg\Execution\Workspace) {
            throw new InvalidArgumentException('Queued export plans accept fresh native builders and serializable callbacks, not runtime services.');
        }

        if ($objects->contains($value)) {
            return ['type' => 'reference', 'id' => $objects[$value]];
        }

        $id = count($objects);
        $objects[$value] = $id;
        $properties = [];
        // Package classes are final and extend nothing, so their own properties are the whole state.
        foreach (new ReflectionClass($value)->getProperties() as $property) {
            if (! $property->isStatic() && $property->isInitialized($value)) {
                $properties[] = [$value::class, $property->name, $this->encode($property->getValue($value), $objects)];
            }
        }

        return ['type' => 'object', 'class' => $value::class, 'id' => $id, 'properties' => $properties];
    }

    private function decode(mixed $value, array &$objects): mixed
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
            new \ReflectionProperty($class, $name)->setValue($object, $this->decode($item, $objects));
        }

        return $object;
    }
}
