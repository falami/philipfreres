<?php

namespace App\Service\Photo;


use Psr\Log\LoggerInterface;
use App\Service\FileUploader;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;

class PhotoManager
{

    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }


    public function deleteImageIfExists(?string $filename, string $uploadPath): void
    {
        if ($filename) {
            $filepath = $uploadPath . '/' . $filename;
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
    }



    public function handleImageUpload($form, string $fieldName, callable $setter, FileUploader $fileUploader, string $uploadPath, int $sizeW, int $sizeH, ?string $oldFilename = null): void
    {
        $imageFile = $form->get($fieldName)->getData();
        if ($imageFile) {
            // Supprimer l'ancien fichier s'il existe
            if ($oldFilename) {
                $oldFilePath = $uploadPath . '/' . $oldFilename;
                if (file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                }
            }

            // Upload du nouveau fichier
            $fileName = $fileUploader->upload($imageFile, $uploadPath);

            // Redimensionnement
            $imagine = new Imagine();
            $imagePath = $uploadPath . '/' . $fileName;
            $size = new Box($sizeW, $sizeH);
            $mode = ImageInterface::THUMBNAIL_OUTBOUND;

            $imagine->open($imagePath)
                ->thumbnail($size, $mode)
                ->save($imagePath);

            $setter($fileName);
        }
    }


    public function deleteIfExists(string $basePath, string $filename): void
    {
        $path = rtrim($basePath, '/') . '/' . $filename;
        if (is_file($path)) @unlink($path);
    }



    public function handleSingleImageUpload(
        \Symfony\Component\HttpFoundation\File\UploadedFile $file,
        callable $setter,
        \App\Service\FileUploader $fileUploader,
        string $uploadPath,
        int $sizeW = 1800,
        int $sizeH = 1200,
        ?string $oldFilename = null
    ): void {
        $filename = $fileUploader->upload($file, $uploadPath);
        $imagePath = rtrim($uploadPath, '/') . '/' . $filename;

        if (is_file($imagePath)) {
            $this->normalizeImageOrientation($imagePath);

            $imagine = new Imagine();
            $size = new Box($sizeW, $sizeH);

            $imagine->open($imagePath)
                ->thumbnail($size, ImageInterface::THUMBNAIL_INSET)
                ->save($imagePath, [
                    'quality' => 92,
                    'jpeg_quality' => 92,
                    'png_compression_level' => 7,
                ]);
        }

        $setter($filename);

        if ($oldFilename && $oldFilename !== $filename) {
            $oldPath = rtrim($uploadPath, '/') . '/' . $oldFilename;

            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }
    }


    private function normalizeImageOrientation(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $mime = mime_content_type($path);

        if (!in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
            return;
        }

        if (
            !function_exists('exif_read_data') ||
            !function_exists('imagecreatefromjpeg') ||
            !function_exists('imagerotate')
        ) {
            return;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        if ($orientation === 1) {
            return;
        }

        $image = @imagecreatefromjpeg($path);

        if (!$image) {
            return;
        }

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if ($rotated !== $image) {
            imagedestroy($image);
        }

        imagejpeg($rotated, $path, 92);
        imagedestroy($rotated);
    }
}
