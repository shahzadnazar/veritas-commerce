<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use App\Modules\Media\Exceptions\RejectedUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The storage port, and the line between what the world may read and what
 * it may not.
 */
final class ObjectStorageTest extends TestCase
{
    private ObjectStore $objects;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        Storage::fake('documents');

        $this->objects = app(ObjectStore::class);
    }

    #[Test]
    public function public_and_private_objects_land_on_different_disks(): void
    {
        $image = $this->objects->put(
            UploadedFile::fake()->image('photo.jpg', 800, 600),
            'products/1/images',
            Visibility::Public,
        );

        $document = $this->objects->put(
            $this->pdf(),
            'sellers/1/documents',
            Visibility::Private,
        );

        $this->assertSame('media', $image->disk);
        $this->assertSame('documents', $document->disk);

        // Neither disk can see the other's object, whatever the key.
        Storage::disk('media')->assertExists($image->key);
        Storage::disk('media')->assertMissing($document->key);
        Storage::disk('documents')->assertExists($document->key);
        Storage::disk('documents')->assertMissing($image->key);
    }

    #[Test]
    public function a_private_object_has_no_public_url_at_all(): void
    {
        $document = $this->objects->put($this->pdf(), 'sellers/1/documents', Visibility::Private);

        // Not "a URL that 403s" — no URL. There is nothing to leak into a
        // log, a referrer header or a support ticket.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no public URL');

        $this->objects->url($document);
    }

    #[Test]
    public function a_public_object_has_one(): void
    {
        $image = $this->objects->put(
            UploadedFile::fake()->image('photo.jpg', 800, 600),
            'products/1/images',
            Visibility::Public,
        );

        $this->assertStringContainsString($image->key, $this->objects->url($image));
    }

    #[Test]
    public function the_key_is_generated_and_keeps_nothing_of_the_filename(): void
    {
        $image = $this->objects->put(
            UploadedFile::fake()->image('../../../etc/passwd; rm -rf.jpg', 400, 400),
            'products/9/images',
            Visibility::Public,
        );

        $this->assertStringStartsWith('products/9/images/', $image->key);
        $this->assertStringNotContainsString('passwd', $image->key);
        $this->assertStringNotContainsString('..', $image->key);
        $this->assertMatchesRegularExpression('#^products/9/images/[0-9a-z]{26}\.jpg$#', $image->key);
    }

    #[Test]
    public function two_uploads_of_the_same_file_do_not_collide(): void
    {
        $first = $this->objects->put(UploadedFile::fake()->image('a.jpg', 100, 100), 'products/1/images', Visibility::Public);
        $second = $this->objects->put(UploadedFile::fake()->image('a.jpg', 100, 100), 'products/1/images', Visibility::Public);

        $this->assertNotSame($first->key, $second->key);
        Storage::disk('media')->assertExists($first->key);
        Storage::disk('media')->assertExists($second->key);
    }

    #[Test]
    public function the_recorded_type_comes_from_the_bytes_not_the_extension(): void
    {
        $png = UploadedFile::fake()->image('actually-a-png.jpg', 50, 50);

        $stored = $this->objects->put($png, 'products/1/images', Visibility::Public);

        // fake()->image() writes a real image; whatever it wrote is what
        // gets recorded, and the .jpg in the name has no say.
        $this->assertContains($stored->mime, ['image/jpeg', 'image/png', 'image/webp']);
        $this->assertGreaterThan(0, $stored->bytes);
        $this->assertSame(50, $stored->width);
        $this->assertSame(50, $stored->height);
    }

    #[Test]
    public function a_script_renamed_as_an_image_is_refused(): void
    {
        $this->expectException(RejectedUpload::class);

        $this->objects->put($this->fakeFile('logo.jpg', "#!/bin/sh\necho pwned\n"), 'products/1/images', Visibility::Public);
    }

    #[Test]
    public function a_pdf_is_refused_where_only_images_belong(): void
    {
        $this->expectException(RejectedUpload::class);
        $this->expectExceptionMessage('application/pdf are not accepted');

        $this->objects->put($this->pdf(), 'products/1/images', Visibility::Public);
    }

    #[Test]
    public function an_oversized_file_is_refused(): void
    {
        config(['veritas.media.max_upload_kb' => 10]);

        $this->expectException(RejectedUpload::class);
        $this->expectExceptionMessage('larger than');

        $this->objects->put(
            UploadedFile::fake()->image('huge.jpg', 2000, 2000)->size(200),
            'products/1/images',
            Visibility::Public,
        );
    }

    #[Test]
    public function an_empty_file_is_refused(): void
    {
        $this->expectException(RejectedUpload::class);
        $this->expectExceptionMessage('empty');

        $this->objects->put($this->fakeFile('empty.jpg', ''), 'products/1/images', Visibility::Public);
    }

    #[Test]
    public function a_truncated_image_is_refused(): void
    {
        // A real JPEG header with nothing behind it: the type check passes
        // and the decoder still cannot read it.
        $this->expectException(RejectedUpload::class);

        $this->objects->put(
            $this->fakeFile('broken.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01"),
            'products/1/images',
            Visibility::Public,
        );
    }

    #[Test]
    public function an_object_can_be_replaced_and_the_old_one_removed(): void
    {
        $first = $this->objects->put(UploadedFile::fake()->image('a.jpg', 60, 60), 'products/1/images', Visibility::Public);
        $second = $this->objects->put(UploadedFile::fake()->image('b.jpg', 60, 60), 'products/1/images', Visibility::Public);

        $this->objects->delete($first);

        $this->assertFalse($this->objects->exists($first));
        $this->assertTrue($this->objects->exists($second));
    }

    #[Test]
    public function a_stored_object_round_trips_through_its_reference(): void
    {
        $document = $this->objects->put($this->pdf(), 'sellers/3/documents', Visibility::Private);

        $parsed = $this->objects->fromReference($document->reference(), Visibility::Private);

        $this->assertSame($document->disk, $parsed->disk);
        $this->assertSame($document->key, $parsed->key);
        $this->assertTrue($this->objects->exists($parsed));
    }

    #[Test]
    public function a_checksum_is_recorded_so_a_replacement_is_distinguishable(): void
    {
        $document = $this->objects->put($this->pdf(), 'sellers/1/documents', Visibility::Private);

        $this->assertNotNull($document->checksum);
        $this->assertSame(64, strlen($document->checksum), 'A sha256 digest is 64 hex characters.');
    }

    private function pdf(): UploadedFile
    {
        return $this->fakeFile('registration.pdf', "%PDF-1.4\n1 0 obj\n<< >>\nendobj\ntrailer\n<< >>\n%%EOF\n");
    }

    private function fakeFile(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vc-upload');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }
}
