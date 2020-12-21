<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

trait InteractsWithInputPath
{
    /**
     * Searches in the $input array for the key bu the $path, and then
     * prepend the $values in front of that key.
     *
     * @param array $input
     * @param string $path
     * @param array $values
     * @return array
     */
    protected static function mergeBeforePathInput(array $input, string $path, array $values = []): array
    {
        $key = array_search($path, $input) - 1;

        return static::mergeBeforeKey($input, $key, $values);
    }

    /**
     * Prepend the given $values in front of the $key in $input.
     *
     * @param array $input
     * @param integer $key
     * @param array $values
     * @return array
     */
    protected static function mergeBeforeKey(array $input, int $key, array $values = []): array
    {
        return array_merge(
            array_slice($input, 0, $key),
            $values,
            array_slice($input, $key)
        );
    }
}
