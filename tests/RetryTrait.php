<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Exception;
use PHPUnit\Util\Annotation\Registry;
use Throwable;

/**
 * https://gist.github.com/makasim/989fcaa6da8ff579f7914d973e68280c
 */
trait RetryTrait
{
    public function runBare(): void
    {
        $e = null;

        $numberOfRetires = $this->getNumberOfRetries();

        if (false == is_numeric($numberOfRetires)) {
            throw new \LogicException(sprintf('The $numberOfRetires must be a number but got "%s"', var_export($numberOfRetires, true)));
        }

        $numberOfRetires = (int) $numberOfRetires;

        if ($numberOfRetires <= 0) {
            throw new \LogicException(sprintf('The $numberOfRetires must be a positive number greater than 0 but got "%s".', $numberOfRetires));
        }

        for ($i = 0; $i < $numberOfRetires; ++$i) {
            try {
                parent::runBare();

                return;
            } catch (Throwable $e) {
                // last one thrown below
            }
        }

        if ($e) {
            throw $e;
        }
    }

    /**
     * @return int
     */
    private function getNumberOfRetries()
    {
        $annotations = $this->getAnnotations();

        if (isset($annotations['method']['retry'][0])) {
            return $annotations['method']['retry'][0];
        }

        if (isset($annotations['class']['retry'][0])) {
            return $annotations['class']['retry'][0];
        }

        return 1;
    }

    public function getAnnotations(): array
    {
        $className  = get_class($this);
        $methodName = $this->getName() ?: '';

        $registry = Registry::getInstance();

        if ($methodName !== null) {
            try {
                return [
                    'method' => $registry->forMethod($className, $methodName)->symbolAnnotations(),
                    'class'  => $registry->forClassName($className)->symbolAnnotations(),
                ];
            } catch (Exception $methodNotFound) {
                // ignored
            }
        }

        return [
            'method' => null,
            'class'  => $registry->forClassName($className)->symbolAnnotations(),
        ];
    }
}
