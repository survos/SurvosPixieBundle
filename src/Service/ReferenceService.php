<?php

declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use App\Entity\Sheet;
use App\Service\SpreadsheetService;
use App\Service\UploaderHelper;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Entity\Reference;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\File;

class ReferenceService
{
    public function __construct(
        private SpreadsheetService $spreadsheetService, // for the path
        private UploaderHelper $uploaderHelper,
        protected LoggerInterface             $logger,

        private CacheManager $cacheManager,
        private ParameterBagInterface $bag,

    )
    {
    }

    /**
     * copies a local image to s3 or the local image directory, before creating thumbnails
     *
     * @param Reference $reference
     * @return string
     */
    public function processReference(Reference $reference): string
    {
        $originalFilename = $reference->getOriginalFilename();
        $s3Filename = $reference->getS3Path();
        $projectCode = $reference->getProjectCode();
        $s3FilenameWithExtension = $this->uploadImage($reference, $originalFilename, $s3Filename, $projectCode);

        return $s3FilenameWithExtension;

        // not sure why this doesn't work right for certain images like goitia/mg-obras/image32.jpeg

//        if ($s3FilenameWithExtension = $reference->getS3Path()) {
//            foreach (['squared_thumbnail_tiny', 'squared_thumbnail_medium'] as $liipFilter) {
//                $resolvedPath = $this->cacheManager->resolve($s3FilenameWithExtension, $liipFilter);
//                dump($resolvedPath);
//
//
////                $resolvedPath = $this->cacheManager->getBrowserPath($s3FilenameWithExtension, $liipFilter);
//                $this->logger->info(sprintf('%s (%s) has been resolved to %s', $s3FilenameWithExtension, $liipFilter, $resolvedPath));
//                $actualPath = str_replace('http://localhost/', $this->bag->get('kernel.project_dir') . '/public/', $resolvedPath);
//                $actualPath = str_replace('/resolve', '', $actualPath);
//                if (!file_exists($actualPath)) {
//                    # https://stackoverflow.com/questions/28503279/how-to-give-php-write-access-to-certain-directories-created-by-git
//                    $this->logger->error("$actualPath does not exist ($resolvedPath");
//                dd($resolvedPath, $actualPath);
//                }
//            }
//        }
    }


public function importReferenceData(array $referenceData)
{
    //        foreach ($instanceData->getReferences() ?? [] as $idx => $reference) {
    $originalFilename = $referenceData['originalFilename'];
    $s3Path = $referenceData['s3Path'];

    // @todo: add image index marker, e.g. front/back, cellula, primary, etc.

//            dd($instance->getReferences()->count(), $reference);
//        $s3Filename = sprintf(
//            "%s/%s.%s-%d",
//            $instance->getProjectCode(),
//            $instance->getProjectCoreCode(),
//            $instance->getCode(),
//            $idx
//        );
    if (!$reference = $instance->getReferenceByS3($s3Path)) {
        $reference = (new Reference($instance))
            ->setOriginalFilename($originalFilename) // mostly for debugging
            ->setLocalFilename($this->bag->get('spreadsheet_images_path') . Sheet::zipToLocalFile($originalFilename, $instance->getProjectCode()))
            ->setCode($s3Filename)
            ->setS3Path($s3FilenameWithExtension);

    }
    $this->processReference($reference);


    }

    // returns new filename WITH proper extension.
    private function uploadImage(Reference $reference, string $localFilename, string $newFilename, string $projectCode): ?string
    {

        if (str_starts_with($localFilename, 'zip://')) {
            $localFilename = $this->spreadsheetService->getSpreadsheetImagesDir() . Sheet::zipToLocalFile($localFilename, $projectCode);
            assert(file_exists($localFilename), "missing $localFilename");
        }

        if (!file_exists($localFilename)) {
            $localFilename = $this->spreadsheetService->getSpreadsheetImagesDir() . $localFilename;
            assert(file_exists($localFilename), $localFilename . ' does not exist');
        }
        $file = new File($localFilename);
        $isPublic = true;

        $newFilenameWithExtension = $this->uploaderHelper->uploadFile($file, $newFilename, $isPublic, $reference);
        assert($newFilename == $newFilenameWithExtension, "mismatch " . $newFilenameWithExtension);
        $mimeType = $file->getMimeType();
//        dd($mimeType, $newFilenameWithExtension, $newFilename, $file->guessExtension());
        $reference
            ->setMimeType($mimeType)
            ->setUploaded(true);
        //$ php bin/console liip:imagine:cache:resolve relative/path/to/image1.jpg relative/path/to/image2.jpg

        return $newFilenameWithExtension;
    }

}

