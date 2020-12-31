<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;

class DiskTest extends TestCase
{
    /** @test */
    public function it_can_normalize_paths()
    {
        $this->assertEquals('test.mp4', Disk::normalizePath('test.mp4'));
        $this->assertEquals('/test.mp4', Disk::normalizePath('/test.mp4'));
        $this->assertEquals('/users/pascal/test.mp4', Disk::normalizePath('/users/pascal/temp/../test.mp4'));

        $this->assertEquals('D:/test.mp4', Disk::normalizePath('D://test.mp4'));
        $this->assertEquals('D:/test.mp4', Disk::normalizePath('D:\test.mp4'));
        $this->assertEquals('D:/test.mp4', Disk::normalizePath('D:\\\test.mp4'));
        $this->assertEquals('D:/test.mp4', Disk::normalizePath('D:\\\\test.mp4'));
        $this->assertEquals('D:/users/pascal/test.mp4', Disk::normalizePath('D:/users/pascal/temp/../test.mp4'));
    }
}
