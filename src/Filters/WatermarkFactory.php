<?php

namespace ProtoneMedia\LaravelFFMpeg\Filters;

use Illuminate\Support\Traits\ForwardsCalls;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\Filesystem\MediaOnNetwork;
use Spatie\Image\Image;

/**
 * Partly based on this PR:
 * https://github.com/PHP-FFMpeg/PHP-FFMpeg/pull/754/files
 */
class WatermarkFactory
{
    use ForwardsCalls;

    /** position constants */
    public const LEFT   = 'left';
    public const RIGHT  = 'right';
    public const CENTER = 'center';
    public const TOP    = 'top';
    public const BOTTOM = 'bottom';

    /**
     * @var \ProtoneMedia\LaravelFFMpeg\Filesystem\Disk
     */
    private $disk;

    /**
     * @var \ProtoneMedia\LaravelFFMpeg\Filesystem\Media
     */
    private $media;

    /**
     * Offset values.
     */
    private $top;
    private $right;
    private $bottom;
    private $left;

    /**
     * @var \Spatie\Image\Image
     */
    private $image;

    /**
     * Array with the horizontal (x) and verical (y) alignment.
     *
     * @var array
     */
    private $alignments = [];

    /**
     * Uses the 'filesystems.default' disk as default.
     */
    public function __construct()
    {
        $this->disk = Disk::make(config('filesystems.default'));
    }

    /**
     * Set the disk to open files from.
     */
    public function fromDisk($disk): self
    {
        $this->disk = Disk::make($disk);

        return $this;
    }

    /**
     * Instantiates a Media object for the given path.
     */
    public function open(string $path): self
    {
        $this->media = Media::make($this->disk, $path);

        return $this;
    }

    /**
     * Instantiates a MediaOnNetwork object for the given url and transforms
     * it into a Media object.
     *
     * @param string $path
     * @param array $headers
     * @param callable $withCurl
     * @return self
     */
    public function openUrl(string $path, array $headers = [], callable $withCurl = null): self
    {
        $this->media = MediaOnNetwork::make($path, $headers)->toMedia($withCurl);

        return $this;
    }

    /**
     * Sets the offset from to top.
     *
     * @param integer $offset
     * @return self
     */
    public function top($offset = 0): self
    {
        $this->top    = $offset;
        $this->bottom = null;

        return $this;
    }

    /**
     * Sets the offset from the right.
     *
     * @param integer $offset
     * @return self
     */
    public function right($offset = 0): self
    {
        $this->right = $offset;
        $this->left  = null;

        return $this;
    }

    /**
     * Sets the offset from the bottom.
     *
     * @param integer $offset
     * @return self
     */
    public function bottom($offset = 0): self
    {
        $this->bottom = $offset;
        $this->top    = null;

        return $this;
    }

    /**
     * Sets the offset from the left.
     *
     * @param integer $offset
     * @return self
     */
    public function left($offset = 0): self
    {
        $this->left  = $offset;
        $this->right = null;

        return $this;
    }

    /**
     * Setter for the horizontal alignment with an optional offset.
     *
     * @param string $alignment
     * @param integer $offset
     * @return self
     */
    public function horizontalAlignment(string $alignment, $offset = 0): self
    {
        switch ($alignment) {
            case self::LEFT:
                $this->alignments['x'] = $offset;
                break;
            case self::CENTER:
                $this->alignments['x'] = "(W-w)/2+{$offset}";
                break;
            case self::RIGHT:
                $this->alignments['x'] = "W-w+{$offset}";
                break;
        }

        return $this;
    }

    /**
     * Setter for the vertical alignment with an optional offset.
     *
     * @param string $alignment
     * @param integer $offset
     * @return self
     */
    public function verticalAlignment(string $alignment, $offset = 0): self
    {
        switch ($alignment) {
            case self::TOP:
                $this->alignments['y'] = $offset;
                break;
            case self::CENTER:
                $this->alignments['y'] = "(H-h)/2+{$offset}";
                break;
            case self::BOTTOM:
                $this->alignments['y'] = "H-h+{$offset}";
                break;
        }

        return $this;
    }

    /**
     * Returns the full path to the watermark file.
     *
     * @return string
     */
    public function getPath(): string
    {
        if (!$this->image) {
            return $this->media->getLocalPath();
        }

        $path = Disk::makeTemporaryDisk()
            ->makeMedia($this->media->getFilename())
            ->getLocalPath();

        $this->image->save($path);

        return $path;
    }

    /**
     * Returns a new instance of the WatermarkFilter.
     *
     * @return \FFMpeg\Filters\Video\WatermarkFilter
     */
    public function get(): WatermarkFilter
    {
        $path = $this->getPath();

        if (!empty($this->alignments)) {
            return new WatermarkFilter($path, $this->alignments);
        }

        $coordinates = ['position' => 'relative'];

        foreach (['top', 'right', 'bottom', 'left'] as $attribute) {
            if (is_null($this->$attribute)) {
                continue;
            }

            $coordinates[$attribute] = $this->$attribute;
        }

        return new WatermarkFilter($path, $coordinates);
    }

    /**
     * Returns an instance of Image.
     *
     * @return \Spatie\Image\Image
     */
    private function image(): Image
    {
        if (!$this->image) {
            $this->image = Image::load($this->media->getLocalPath());
        }

        return $this->image;
    }

    /**
     * Forwards calls to the Image manipulation class.
     */
    public function __call($method, $arguments)
    {
        $this->forwardCallTo($this->image(), $method, $arguments);

        return $this;
    }
}
