<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\GeneratedFile;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class GeneratedFileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'filesystems.disks.generated-local' => ['driver' => 'local', 'root' => $this->root.'/files'],
            'filesystems.disks.generated-remote' => ['driver' => 'memory'],
        ]);
    }

    #[TestWith(['generated-local', 'generated-remote'], 'local disk')]
    #[TestWith(['generated-remote', 'generated-local'], 'remote disk')]
    public function test_exported_files_delete_only_the_exact_local_or_remote_destination(string $disk, string $otherDisk): void
    {
        $ffmpeg = $this->useRealFFMpeg();
        $process = Process::run([$ffmpeg, '-v', 'error', '-f', 'lavfi', '-i',
            'sine=frequency=440:duration=0.1', '-c:a', 'pcm_s16le', $this->root.'/source.wav']);
        $this->assertProcessSucceeded($process);
        $local = Storage::disk('generated-local');
        $local->put('source.wav', file_get_contents($this->root.'/source.wav'));
        $source = $local->get('source.wav');
        $filesystem = Storage::disk($disk);
        $filesystem->put('nested/media.wav.backup', 'keep neighbor');
        $filesystem->put('nested/other.wav', 'keep sibling');
        $other = Storage::disk($otherDisk);
        $other->put('nested/media.wav', 'keep other disk');
        $file = FFMpeg::disk('generated-local')->open('source.wav')->export()->audio('copy')
            ->toDisk($disk)->save('nested/media.wav')->file();
        $metadata = $file->toArray();
        $this->assertTrue($file->owned);
        $this->assertTrue($filesystem->exists($file->path));
        $this->assertTrue($file->delete());
        $this->assertFalse($filesystem->exists('nested/media.wav'));
        $this->assertSame('keep neighbor', $filesystem->get('nested/media.wav.backup'));
        $this->assertSame('keep sibling', $filesystem->get('nested/other.wav'));
        $this->assertSame('keep other disk', $other->get('nested/media.wav'));
        $this->assertSame($metadata, $file->toArray());
        $this->assertSame($source, $local->get('source.wav'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_borrowed_files_refuse_deletion_without_calling_the_filesystem(): void
    {
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldNotReceive('delete');
        $file = new GeneratedFile($filesystem, null, 'borrowed/key.bin', 16, role: 'key', owned: false);

        $this->assertFalse($file->delete());
    }

    #[TestWith(['generated-local'])]
    #[TestWith(['generated-remote'])]
    public function test_borrowed_files_are_kept_on_every_disk(string $disk): void
    {
        $filesystem = Storage::disk($disk);
        $filesystem->put('borrowed/key.bin', 'borrowed secret');
        $file = new GeneratedFile($filesystem, $disk, 'borrowed/key.bin', 15, role: 'key', owned: false);

        $this->assertFalse($file->delete());
        $this->assertSame('borrowed secret', $filesystem->get('borrowed/key.bin'));
    }

    public function test_deletion_returns_the_underlying_filesystem_failure(): void
    {
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('delete')->once()->with('nested/exact %03d.bin')->andReturn(false);
        $file = new GeneratedFile($filesystem, null, 'nested/exact %03d.bin', 12);
        $this->assertFalse($file->delete());
    }
}
