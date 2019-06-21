<?php

/**
 * @package    Grav\Framework\Form
 *
 * @copyright  Copyright (C) 2015 - 2019 Trilby Media, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Framework\Form;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Utils;
use Grav\Framework\Form\Interfaces\FormFlashInterface;
use Psr\Http\Message\UploadedFileInterface;
use RocketTheme\Toolbox\File\YamlFile;

class FormFlash implements FormFlashInterface
{
    /** @var string */
    protected $sessionId;
    /** @var string */
    protected $uniqueId;
    /** @var string */
    protected $formName;
    /** @var string */
    protected $url;
    /** @var array */
    protected $user;
    /** @var int */
    protected $createdTimestamp;
    /** @var int */
    protected $updatedTimestamp;
    /** @var array */
    protected $data;
    /** @var array */
    protected $files;
    /** @var array */
    protected $uploadedFiles;
    /** @var string[] */
    protected $uploadObjects;
    /** @var bool */
    protected $exists;

    /**
     * @param string $sessionId
     * @return string
     */
    public static function getSessionTmpDir(string $sessionId): string
    {
        if (!$sessionId) {
            return '';
        }

        return "tmp://forms/{$sessionId}";
    }

    /**
     * @inheritDoc
     */
    public function __construct(string $sessionId, string $uniqueId, string $formName = null)
    {
        $this->sessionId = $sessionId;
        $this->uniqueId = $uniqueId;

        $file = $this->getTmpIndex();
        $this->exists = $file->exists();

        if ($this->exists) {
            try {
                $data = (array)$file->content();
            } catch (\Exception $e) {
                $data = [];
            }
            $this->formName = $content['form'] ?? $formName;
            $this->url = $data['url'] ?? '';
            $this->user = $data['user'] ?? null;
            $this->updatedTimestamp = $data['timestamps']['updated'] ?? time();
            $this->createdTimestamp = $data['timestamps']['created'] ?? $this->updatedTimestamp;
            $this->data = $data['data'] ?? null;
            $this->files = $data['files'] ?? [];
        } else {
            $this->formName = $formName;
            $this->url = '';
            $this->createdTimestamp = $this->updatedTimestamp = time();
            $this->files = [];
        }
    }

    /**
     * @inheritDoc
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * @inheritDoc
     */
    public function getUniqueId(): string
    {
        return $this->uniqueId;
    }

    /**
     * @inheritDoc
     */
    public function getFormName(): string
    {
        return $this->formName;
    }


    /**
     * @inheritDoc
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @inheritDoc
     */
    public function getUsername(): string
    {
        return $this->user['username'] ?? '';
    }

    /**
     * @inheritDoc
     */
    public function getUserEmail(): string
    {
        return $this->user['email'] ?? '';
    }

    /**
     * @inheritDoc
     */
    public function getCreatedTimestamp(): int
    {
        return $this->createdTimestamp;
    }

    /**
     * @inheritDoc
     */
    public function getUpdatedTimestamp(): int
    {
        return $this->updatedTimestamp;
    }


    /**
     * @inheritDoc
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    /**
     * @inheritDoc
     */
    public function setData(?array $data): void
    {
        $this->data = $data;
    }

    /**
     * @inheritDoc
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * @inheritDoc
     */
    public function save(): self
    {
        if (!$this->sessionId) {
            return $this;
        }

        if ($this->data || $this->files) {
            // Only save if there is data or files to be saved.
            $file = $this->getTmpIndex();
            $file->save($this->jsonSerialize());
            $this->exists = true;
        } elseif ($this->exists) {
            // Delete empty form flash if it exists (it carries no information).
            return $this->delete();
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function delete(): self
    {
        if ($this->sessionId) {
            $this->removeTmpDir();
            $this->files = [];
            $this->exists = false;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getFilesByField(string $field): array
    {
        if (!isset($this->uploadObjects[$field])) {
            $objects = [];
            foreach ($this->files[$field] ?? [] as $name => $upload) {
                $objects[$name] = $upload ? new FormFlashFile($field, $upload, $this) : null;
            }
            $this->uploadedFiles[$field] = $objects;
        }

        return $this->uploadedFiles[$field];
    }

    /**
     * @inheritDoc
     */
    public function getFilesByFields($includeOriginal = false): array
    {
        $list = [];
        foreach ($this->files as $field => $values) {
            if (!$includeOriginal && strpos($field, '/')) {
                continue;
            }
            $list[$field] = $this->getFilesByField($field);
        }

        return $list;
    }

    /**
     * @inheritDoc
     */
    public function addUploadedFile(UploadedFileInterface $upload, string $field = null, array $crop = null): string
    {
        $tmp_dir = $this->getTmpDir();
        $tmp_name = Utils::generateRandomString(12);
        $name = $upload->getClientFilename();

        // Prepare upload data for later save
        $data = [
            'name' => $name,
            'type' => $upload->getClientMediaType(),
            'size' => $upload->getSize(),
            'tmp_name' => $tmp_name
        ];

        Folder::create($tmp_dir);
        $upload->moveTo("{$tmp_dir}/{$tmp_name}");

        $this->addFileInternal($field, $name, $data, $crop);

        return $name;
    }


    /**
     * @inheritDoc
     */
    public function addFile(string $filename, string $field, array $crop = null): bool
    {
        if (!file_exists($filename)) {
            throw new \RuntimeException("File not found: {$filename}");
        }

        // Prepare upload data for later save
        $data = [
            'name' => basename($filename),
            'type' => Utils::getMimeByLocalFile($filename),
            'size' => filesize($filename),
        ];

        $this->addFileInternal($field, $data['name'], $data, $crop);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function removeFile(string $name, string $field = null): bool
    {
        if (!$name) {
            return false;
        }

        $field = $field ?: 'undefined';

        $upload = $this->files[$field][$name] ?? null;
        if (null !== $upload) {
            $this->removeTmpFile($upload['tmp_name'] ?? '');
        }
        $upload = $this->files[$field . '/original'][$name] ?? null;
        if (null !== $upload) {
            $this->removeTmpFile($upload['tmp_name'] ?? '');
        }

        // Mark file as deleted.
        $this->files[$field][$name] = null;
        $this->files[$field . '/original'][$name] = null;

        unset(
            $this->uploadedFiles[$field][$name],
            $this->uploadedFiles[$field . '/original'][$name]
        );

        return true;
    }

    /**
     * @inheritDoc
     */
    public function clearFiles()
    {
        foreach ($this->files as $field => $files) {
            foreach ($files as $name => $upload) {
                $this->removeTmpFile($upload['tmp_name'] ?? '');
            }
        }

        $this->files = [];
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return [
            'form' => $this->formName,
            'unique_id' => $this->uniqueId,
            'url' => $this->url,
            'user' => $this->user,
            'timestamps' => [
                'created' => $this->createdTimestamp,
                'updated' => time(),
            ],
            'data' => $this->data,
            'files' => $this->files
        ];
    }

    /**
     * @param string $url
     * @return $this
     */
    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * @param string|null $username
     * @return $this
     */
    public function setUserName(string $username = null): self
    {
        $this->user['username'] = $username;

        return $this;
    }

    /**
     * @param string|null $email
     * @return $this
     */
    public function setUserEmail(string $email = null): self
    {
        $this->user['email'] = $email;

        return $this;
    }

    /**
     * @return string
     */
    public function getTmpDir(): string
    {
        if (!$this->sessionId) {
            return '';
        }

        return static::getSessionTmpDir($this->sessionId) . '/' . $this->uniqueId;
    }

    /**
     * @return YamlFile
     */
    protected function getTmpIndex(): YamlFile
    {
        // Do not use CompiledYamlFile as the file can change multiple times per second.
        return YamlFile::instance($this->getTmpDir() . '/index.yaml');
    }

    /**
     * @param string $name
     */
    protected function removeTmpFile(string $name): void
    {
        $tmpDir = $this->getTmpDir();
        $filename =  $tmpDir ? $tmpDir . '/' . $name : '';
        if ($name && $filename && is_file($filename)) {
            unlink($filename);
        }
    }

    protected function removeTmpDir(): void
    {
        $tmpDir = $this->getTmpDir();
        if ($tmpDir && file_exists($tmpDir)) {
            Folder::delete($tmpDir);
        }
    }

    /**
     * @param string $field
     * @param string $name
     * @param array $data
     * @param array|null $crop
     */
    protected function addFileInternal(?string $field, string $name, array $data, array $crop = null): void
    {
        if (!$this->sessionId) {
            throw new \RuntimeException('Cannot upload files: no session initialized');
        }

        $field = $field ?: 'undefined';
        if (!isset($this->files[$field])) {
            $this->files[$field] = [];
        }

        $oldUpload = $this->files[$field][$name] ?? null;

        if ($crop) {
            // Deal with crop upload
            if ($oldUpload) {
                $originalUpload = $this->files[$field . '/original'][$name] ?? null;
                if ($originalUpload) {
                    // If there is original file already present, remove the modified file
                    $this->files[$field . '/original'][$name]['crop'] = $crop;
                    $this->removeTmpFile($oldUpload['tmp_name'] ?? '');
                } else {
                    // Otherwise make the previous file as original
                    $oldUpload['crop'] = $crop;
                    $this->files[$field . '/original'][$name] = $oldUpload;
                }
            } else {
                $this->files[$field . '/original'][$name] = [
                    'name' => $name,
                    'type' => $data['type'],
                    'crop' => $crop
                ];
            }
        } else {
            // Deal with replacing upload
            $originalUpload = $this->files[$field . '/original'][$name] ?? null;
            $this->files[$field . '/original'][$name] = null;

            $this->removeTmpFile($oldUpload['tmp_name'] ?? '');
            $this->removeTmpFile($originalUpload['tmp_name'] ?? '');
        }

        // Prepare data to be saved later
        $this->files[$field][$name] = $data;
    }
}
