<?php

namespace App\Tests\Controller;

use App\Entity\Album;
use App\Entity\DispatchCatalog;
use App\Entity\Pictures;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DispatchTest extends WebTestCase
{
    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        if ($this->connection?->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testImportAndCatalogueAccessArePrivate(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $user = (new User())->setEmail('dispatch-admin@example.test')->setPassword('test');
        $album = (new Album())->setName('Dispatch album')->setDescription('Description');
        $em->persist($user);
        $em->persist($album);
        $em->flush();

        $client->request('GET', '/admin/pictures/bulk/dispatch');
        self::assertResponseRedirects('/login');
        $client->request('POST', '/admin/pictures/bulk/dispatch/create');
        self::assertResponseRedirects('/login');
        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/pictures/bulk/dispatch');
        self::assertResponseIsSuccessful();
        $main = $crawler->filter('[data-controller="bulk-upload"]');
        $createToken = $main->attr('data-bulk-upload-dispatch-token-value');
        $uploadToken = $main->attr('data-bulk-upload-token-value');
        $client->request('POST', '/admin/pictures/bulk/dispatch/create', ['_token' => $createToken, 'name' => 'Private event']);
        self::assertResponseStatusCodeSame(201);
        $credentials = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertMatchesRegularExpression('/^\d{6}$/', $credentials['idCode']);
        self::assertMatchesRegularExpression('/^\d{6}$/', $credentials['code']);
        self::assertNotSame($credentials['idCode'], $credentials['code']);
        $em->clear();
        $dispatch = $em->find(DispatchCatalog::class, $credentials['id']);
        self::assertTrue(password_verify($credentials['code'], $dispatch->getCode()));

        $file = tempnam(sys_get_temp_dir(), 'dispatch-test-');
        $image = imagecreatetruecolor(2, 2);
        imagepng($image, $file);
        $client->request('POST', '/admin/pictures/addDispatch', [
            '_token' => $uploadToken, 'dispatch' => $credentials['id'], 'album' => $album->getId(),
            'title' => 'Private photo', 'description' => 'Private description', 'metadata' => 'private', 'uploadDate' => '2026-01-01',
        ], ['file' => new UploadedFile($file, 'test.png', 'image/png', null, true)]);
        self::assertResponseStatusCodeSame(201);
        $payload = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $path = static::getContainer()->getParameter('kernel.project_dir').'/var/private/pictures/'.basename($payload['url']);

        try {
            $em->clear();
            $picture = $em->find(Pictures::class, $payload['id']);
            self::assertFalse($picture->getIsPublic());
            self::assertSame($credentials['id'], $picture->getDispatchCatalog()->getId());
            self::assertSame($album->getId(), $picture->getAlbum()->getId());
            self::assertFileExists($path);
            $file = tempnam(sys_get_temp_dir(), 'dispatch-test-');
            imagepng(imagecreatetruecolor(2, 2), $file);
            $client->request('POST', '/admin/pictures/addDispatch', [
                '_token' => $uploadToken, 'dispatch' => $credentials['id'], 'album' => $album->getId(),
                'title' => 'Second photo', 'description' => 'd', 'metadata' => 'm', 'uploadDate' => '2026-01-01',
            ], ['file' => new UploadedFile($file, 'second.png', 'image/png', null, true)]);
            self::assertResponseStatusCodeSame(201);
            $second = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $secondPath = static::getContainer()->getParameter('kernel.project_dir').'/var/private/pictures/'.basename($second['url']);

            $client->request('GET', $payload['url']);
            self::assertResponseIsSuccessful();
            $client->restart();
            $client->request('GET', $payload['url']);
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/');
            self::assertSelectorTextNotContains('body', 'Private photo');
            $client->request('GET', '/albums/'.$album->getId());
            self::assertSelectorTextNotContains('body', 'Private photo');
            $client->request('GET', '/pictures');
            self::assertSelectorNotExists('img[src="'.$payload['url'].'"]');

            $crawler = $client->request('GET', '/dispatch');
            $token = $crawler->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/dispatch', ['_token' => $token, 'idCode' => $credentials['idCode'], 'code' => 'abcdef']);
            self::assertSelectorTextContains('body', 'Invalid identifier or access code');
            $client->request('POST', '/dispatch', ['_token' => $token, 'idCode' => $credentials['idCode'], 'code' => $credentials['code']]);
            self::assertResponseRedirects('/dispatch/'.$credentials['idCode']);
            $client->followRedirect();
            self::assertSelectorTextContains('body', 'Private photo');
            self::assertSelectorTextContains('body', 'Second photo');
            $client->request('GET', $payload['url']);
            self::assertResponseIsSuccessful();
            $client->request('GET', '/admin/pictures');
            self::assertResponseRedirects('/login');

            $other = (new DispatchCatalog())->setNom('Other')->setIdCode('999999')->setCode(password_hash('222222', PASSWORD_DEFAULT));
            if ($other->getIdCode() === $credentials['idCode']) {
                $other->setIdCode('999998');
            }
            $em->persist($other);
            $em->flush();
            $otherPicture = (new Pictures())->setTitle('Other photo')->setDescription('d')->setMetadata('m')
                ->setUploadDate(new \DateTime('2026-01-01'))->setIsPublic(false)->setDispatchCatalog($other)
                ->setUrl('/private/pictures/'.str_repeat('a', 32).'.png');
            $em->persist($otherPicture);
            $em->flush();
            $client->request('GET', $otherPicture->getUrl());
            self::assertResponseStatusCodeSame(403);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            if (isset($secondPath) && is_file($secondPath)) {
                unlink($secondPath);
            }
        }
    }

    public function testCodeAttemptsAreLockedAcrossSessions(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $dispatch = (new DispatchCatalog())->setNom('Locked')->setIdCode('101010')->setCode(password_hash('123456', PASSWORD_DEFAULT));
        $em->persist($dispatch);
        $em->flush();
        $dispatchId = $dispatch->getId();

        for ($i = 0; $i < 5; ++$i) {
            $crawler = $client->request('GET', '/dispatch');
            $token = $crawler->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/dispatch', ['_token' => $token, 'idCode' => '101010', 'code' => '000000']);
            self::assertSelectorTextContains('body', 'Invalid identifier or access code');
            $client->restart();
        }
        $crawler = $client->request('GET', '/dispatch');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/dispatch', ['_token' => $token, 'idCode' => '101010', 'code' => '123456']);
        self::assertSelectorTextContains('body', 'Too many attempts');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $dispatch = $em->find(DispatchCatalog::class, $dispatchId);
        $dispatch->setLockedUntil(new \DateTimeImmutable('-1 minute'));
        $em->flush();
        $client->request('POST', '/dispatch', ['_token' => $token, 'idCode' => '101010', 'code' => '123456']);
        self::assertResponseRedirects('/dispatch/101010');
    }

    public function testResettingCodeInvalidatesPreviousCodeAndVisitorSession(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $user = (new User())->setEmail('reset-admin@example.test')->setPassword('test');
        $dispatch = (new DispatchCatalog())->setNom('Event')->setIdCode('001234')->setCode(password_hash('123456', PASSWORD_DEFAULT));
        $picture = (new Pictures())->setTitle('Private')->setDescription('d')->setMetadata('m')
            ->setUploadDate(new \DateTime('2026-01-01'))->setIsPublic(false)->setDispatchCatalog($dispatch)
            ->setUrl('/private/pictures/'.str_repeat('b', 32).'.png');
        $em->persist($user);
        $em->persist($dispatch);
        $em->persist($picture);
        $em->flush();
        $id = $dispatch->getId();

        $client->request('POST', '/admin/pictures/bulk/dispatch/'.$id.'/code');
        self::assertResponseRedirects('/login');
        $crawler = $client->request('GET', '/dispatch');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/dispatch', ['_token' => $token, 'idCode' => '001234', 'code' => '123456']);
        self::assertResponseRedirects('/dispatch/001234');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Private');

        $admin = new KernelBrowser(static::$kernel);
        $admin->disableReboot();
        $admin->loginUser($user);
        $crawler = $admin->request('GET', '/admin/pictures/bulk/dispatch');
        $resetToken = $crawler->filter('[data-controller="bulk-upload"]')->attr('data-bulk-upload-dispatch-reset-token-value');
        $admin->request('POST', '/admin/pictures/bulk/dispatch/'.$id.'/code', ['_token' => 'invalid']);
        self::assertSame(403, $admin->getResponse()->getStatusCode());
        $admin->request('POST', '/admin/pictures/bulk/dispatch/'.$id.'/code', ['_token' => $resetToken]);
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        $result = json_decode($admin->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('001234', $result['idCode']);
        self::assertMatchesRegularExpression('/^\d{6}$/', $result['code']);
        self::assertNotSame('123456', $result['code']);
        self::assertNotSame('001234', $result['code']);

        $client->request('GET', $picture->getUrl());
        self::assertResponseStatusCodeSame(403);
        $crawler = $client->request('GET', '/dispatch/001234');
        self::assertSelectorNotExists('img[src="'.$picture->getUrl().'"]');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/dispatch', ['_token' => $token, 'idCode' => '001234', 'code' => '123456']);
        self::assertSelectorTextContains('body', 'Invalid identifier or access code');
        $client->request('POST', '/dispatch', ['_token' => $token, 'idCode' => '001234', 'code' => $result['code']]);
        self::assertResponseRedirects('/dispatch/001234');
    }

    public function testNormalBulkStaysPublicAndDispatchIsRequired(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $user = (new User())->setEmail('bulk-admin@example.test')->setPassword('test');
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/pictures/bulk');
        $token = $crawler->filter('[data-controller="bulk-upload"]')->attr('data-bulk-upload-token-value');
        $data = ['_token' => $token, 'title' => 'Public photo', 'description' => 'd', 'metadata' => 'm', 'uploadDate' => '2026-01-01'];

        $file = tempnam(sys_get_temp_dir(), 'dispatch-test-');
        imagepng(imagecreatetruecolor(2, 2), $file);
        try {
            $client->request('POST', '/admin/pictures/addDispatch', $data, ['file' => new UploadedFile($file, 'test.png', 'image/png', null, true)]);
            self::assertResponseStatusCodeSame(422);
            self::assertArrayHasKey('dispatch', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['errors']);
            $client->request('POST', '/admin/pictures/bulk/upload', $data, ['file' => new UploadedFile($file, 'test.png', 'image/png', null, true)]);
            self::assertResponseStatusCodeSame(201);
            $payload = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $path = static::getContainer()->getParameter('kernel.project_dir').'/public'.$payload['url'];
            $em->clear();
            self::assertTrue($em->find(Pictures::class, $payload['id'])->getIsPublic());
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            if (isset($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testDescriptionsAndMetadataAreOptionalForEveryUpload(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $user = (new User())->setEmail('optional-fields@example.test')->setPassword('test');
        $dispatch = (new DispatchCatalog())->setNom('Event')->setIdCode('876543')->setCode(password_hash('123456', PASSWORD_DEFAULT));
        $em->persist($user);
        $em->persist($dispatch);
        $em->flush();
        $client->loginUser($user);
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $files = [];

        try {
            $crawler = $client->request('GET', '/admin/pictures/new');
            self::assertSelectorNotExists('input[name="pictures[description]"][required]');
            self::assertSelectorNotExists('input[name="pictures[metadata]"][required]');
            $token = $crawler->filter('input[name="pictures[_token]"]')->attr('value');
            $file = tempnam(sys_get_temp_dir(), 'picture-test-');
            $files[] = $file;
            imagepng(imagecreatetruecolor(2, 2), $file);
            $client->request('POST', '/admin/pictures/new', ['pictures' => [
                '_token' => $token, 'title' => 'Single optional', 'description' => str_repeat('d', 256),
                'metadata' => str_repeat('m', 256), 'uploadDate' => '2026-01-01',
            ]], ['pictures' => ['imageFile' => new UploadedFile($file, 'single.png', 'image/png', null, true)]]);
            self::assertResponseStatusCodeSame(422);
            self::assertCount(2, $client->getCrawler()->filter('.invalid-feedback'));
            self::assertNull($em->getRepository(Pictures::class)->findOneBy(['title' => 'Single optional']));
            $client->request('POST', '/admin/pictures/new', ['pictures' => [
                '_token' => $token, 'title' => 'Single optional', 'description' => '', 'metadata' => '', 'uploadDate' => '2026-01-01',
            ]], ['pictures' => ['imageFile' => new UploadedFile($file, 'single.png', 'image/png', null, true)]]);
            self::assertResponseRedirects('/admin/pictures');
            $single = $em->getRepository(Pictures::class)->findOneBy(['title' => 'Single optional']);
            self::assertNotNull($single);
            $files[] = $projectDir.'/public'.$single->getUrl();
            self::assertSame('', $single->getDescription());
            self::assertSame('', $single->getMetadata());

            $crawler = $client->request('GET', '/admin/pictures/'.$single->getId().'/edit');
            $editToken = $crawler->filter('input[name="pictures[_token]"]')->attr('value');
            $client->request('POST', '/admin/pictures/'.$single->getId().'/edit', ['pictures' => [
                '_token' => $editToken, 'title' => 'Single optional', 'description' => '', 'metadata' => '', 'uploadDate' => '2026-01-01',
            ]]);
            self::assertResponseRedirects('/admin/pictures');

            $crawler = $client->request('GET', '/admin/pictures/bulk');
            $bulkToken = $crawler->filter('[data-controller="bulk-upload"]')->attr('data-bulk-upload-token-value');
            foreach ([['/admin/pictures/bulk/upload', 'Bulk optional', null], ['/admin/pictures/addDispatch', 'Dispatch optional', $dispatch->getId()]] as [$url, $title, $dispatchId]) {
                $file = tempnam(sys_get_temp_dir(), 'picture-test-');
                $files[] = $file;
                imagepng(imagecreatetruecolor(2, 2), $file);
                $data = ['_token' => $bulkToken, 'title' => $title, 'uploadDate' => '2026-01-01'];
                if ($dispatchId !== null) {
                    $data['dispatch'] = $dispatchId;
                }
                $client->request('POST', $url, $data + ['description' => str_repeat('d', 256), 'metadata' => str_repeat('m', 256)], [
                    'file' => new UploadedFile($file, 'test.png', 'image/png', null, true),
                ]);
                self::assertResponseStatusCodeSame(422);
                $errors = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['errors'];
                self::assertArrayHasKey('description', $errors);
                self::assertArrayHasKey('metadata', $errors);
                $client->request('POST', $url, $data, ['file' => new UploadedFile($file, 'test.png', 'image/png', null, true)]);
                self::assertResponseStatusCodeSame(201);
                $result = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
                $files[] = $dispatchId === null ? $projectDir.'/public'.$result['url'] : $projectDir.'/var/private/pictures/'.basename($result['url']);
                $picture = $em->find(Pictures::class, $result['id']);
                self::assertSame('', $picture->getDescription());
                self::assertSame('', $picture->getMetadata());
                self::assertSame($dispatchId === null, $picture->getIsPublic());
            }
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function testDispatchDownloadsAndConfirmedPublication(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $dispatch = (new DispatchCatalog())->setNom('Event')->setIdCode('111222')->setCode(password_hash('123456', PASSWORD_DEFAULT));
        $other = (new DispatchCatalog())->setNom('Other')->setIdCode('333444')->setCode(password_hash('654321', PASSWORD_DEFAULT));
        $em->persist($dispatch);
        $em->persist($other);
        $root = static::getContainer()->getParameter('kernel.project_dir').'/var/private/pictures';
        if (!is_dir($root)) {
            mkdir($root, 0777, true);
        }
        $paths = [];
        $items = [];
        try {
            foreach ([['First', $dispatch, false], ['Second', $dispatch, false], ['Published', $dispatch, true], ['Other', $other, false]] as [$title, $catalog, $public]) {
                $filename = bin2hex(random_bytes(16)).'.png';
                $paths[] = $root.'/'.$filename;
                file_put_contents($root.'/'.$filename, 'contents-'.$title);
                $picture = (new Pictures())->setTitle($title)->setDescription('d')->setMetadata('m')
                    ->setUploadDate(new \DateTime('2026-01-01'))->setIsPublic($public)->setDispatchCatalog($catalog)
                    ->setUrl('/private/pictures/'.$filename);
                $em->persist($picture);
                $items[$title] = $picture;
            }
            $em->flush();
            $firstId = $items['First']->getId();
            $secondId = $items['Second']->getId();
            $otherId = $items['Other']->getId();

            $client->request('GET', '/dispatch/111222/download');
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/dispatch/111222/pictures/'.$firstId.'/download');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/dispatch/111222/publish');
            self::assertResponseStatusCodeSame(403);

            $crawler = $client->request('GET', '/dispatch');
            $token = $crawler->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/dispatch', ['_token' => $token, 'idCode' => '111222', 'code' => '123456']);
            self::assertResponseRedirects('/dispatch/111222');
            $crawler = $client->followRedirect();
            self::assertSelectorExists('a[href="/dispatch/111222/download"]');
            self::assertSelectorCount(3, 'input[name="pictures[]"]');
            self::assertSelectorExists('input[name="pictures[]"][checked][disabled]');
            self::assertSelectorExists('button[data-action="dispatch-selection#selectAll"]');
            self::assertSelectorExists('.sticky-top a[href="/dispatch/111222/download"]');
            self::assertSelectorExists('.sticky-top button[data-action="dispatch-selection#selectAll"]');
            self::assertSelectorExists('.sticky-top button[data-bs-target="#confirm-publication"]');
            self::assertSelectorExists('#confirm-publication button[data-bs-dismiss="modal"]');
            $publishToken = $crawler->filter('form[action="/dispatch/111222/publish"] input[name="_token"]')->attr('value');
            $client->request('POST', '/dispatch/111222/publish', ['_token' => 'invalid', 'pictures' => [(string) $firstId]]);
            self::assertResponseStatusCodeSame(403);

            $client->request('GET', '/dispatch/111222/pictures/'.$firstId.'/download');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('attachment', $client->getResponse()->headers->get('Content-Disposition'));
            self::assertSame('contents-First', file_get_contents($client->getResponse()->getFile()->getPathname()));
            $client->request('GET', '/dispatch/111222/pictures/'.$otherId.'/download');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/dispatch/111222/download');
            self::assertResponseIsSuccessful();
            self::assertFileDoesNotExist($client->getResponse()->getFile()->getPathname());
            $archivePath = tempnam(sys_get_temp_dir(), 'dispatch-test-zip-');
            file_put_contents($archivePath, $client->getInternalResponse()->getContent());
            try {
                $zip = new \ZipArchive();
                self::assertTrue($zip->open($archivePath));
                self::assertSame(3, $zip->numFiles);
                self::assertSame('contents-First', $zip->getFromName('photo-'.$firstId.'.png'));
                self::assertSame('contents-Second', $zip->getFromName('photo-'.$secondId.'.png'));
                self::assertFalse($zip->getFromName('photo-'.$otherId.'.png'));
                $zip->close();
            } finally {
                if (is_file($archivePath)) {
                    unlink($archivePath);
                }
            }

            $client->request('POST', '/dispatch/111222/publish', ['_token' => $publishToken, 'pictures' => [(string) $firstId]]);
            self::assertResponseRedirects('/');
            $em->clear();
            self::assertTrue($em->find(Pictures::class, $firstId)->getIsPublic());
            self::assertFalse($em->find(Pictures::class, $secondId)->getIsPublic());
            self::assertTrue($em->find(Pictures::class, $items['Published']->getId())->getIsPublic());

            $client->request('POST', '/dispatch/111222/publish', ['_token' => $publishToken, 'pictures' => [(string) $secondId, (string) $otherId]]);
            self::assertResponseStatusCodeSame(404);
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertFalse($em->find(Pictures::class, $secondId)->getIsPublic());
            self::assertFalse($em->find(Pictures::class, $otherId)->getIsPublic());

            $client->restart();
            $client->request('GET', $items['First']->getUrl());
            self::assertResponseIsSuccessful();
            $client->request('GET', $items['Second']->getUrl());
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/');
            self::assertSelectorTextContains('body', 'First');
            self::assertSelectorTextNotContains('body', 'Second');
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
