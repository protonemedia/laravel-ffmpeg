<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Alchemy\BinaryDriver\Exception\ExecutionFailureException;
use FFMpeg\Exception\RuntimeException as BaseException;

class RuntimeException extends BaseException
{
    public static function decorate(BaseException $runtimeException): RuntimeException
    {
        return tap(new static(
            $runtimeException->getMessage(),
            $runtimeException->getCode(),
            $runtimeException
        ), function (self $exception) {
            if (config('laravel-ffmpeg.set_command_and_error_output_on_exception')) {
                $exception->message = $exception->getAlchemyException()->getMessage();
            }
        });
    }

    public function getCommand(): string
    {
        return $this->getAlchemyException()->getCommand();
    }

    public function getErrorOutput(): string
    {
        return $this->getAlchemyException()->getErrorOutput();
    }

    public function getAlchemyException(): ExecutionFailureException
    {
        return $this->getPrevious()->getPrevious();
    }
}
