<?php

namespace App\Tests\Controller;

use App\Entity\Album;
use App\Entity\Pictures;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AlbumManagerTest extends WebTestCase
{
    public function testManagerRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/albums');

        self::assertResponseRedirects('/login');
    }

    public function testManageAlbumAndKeepItsPhotos(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            $user = (new User())->setEmail(bin2hex(random_bytes(8)).'@example.test')->setPassword('test');
            $album = (new Album())->setName('Manager test')->setDescription('Description');
            $picture = (new Pictures())->setTitle('Test photo')->setDescription('Description')
                ->setMetadata('test')->setUrl('/test.jpg')->setUploadDate(new \DateTime('2026-03-01'))
                ->setAlbum($album);
            $em->persist($user);
            $em->persist($album);
            $em->persist($picture);
            $em->flush();
            $albumId = $album->getId();
            $pictureId = $picture->getId();
            $client->loginUser($user);

            $crawler = $client->request('GET', '/admin/albums');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Manage albums');
            self::assertCount(1, $crawler->filter(sprintf('a[href="/admin/albums/%d/edit?from=manager"]', $albumId)));
            $client->click($crawler->filter('a[href="/admin/albums/new?from=manager"]')->link());
            self::assertResponseIsSuccessful();
            $client->submitForm('Save', ['album[name]' => 'New album', 'album[description]' => 'New description']);
            self::assertResponseRedirects('/admin/albums');

            $crawler = $client->followRedirect();
            self::assertSelectorTextContains('body', 'New album');
            $client->click($crawler->filter(sprintf('a[href="/admin/albums/%d/edit?from=manager"]', $albumId))->link());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/admin/albums"]');
            $client->submitForm('Save', ['album[name]' => 'Updated album', 'album[description]' => 'Updated description']);
            self::assertResponseRedirects('/admin/albums');

            $crawler = $client->followRedirect();
            self::assertSelectorTextContains('body', 'Updated album');
            $deleteForm = $crawler->filter(sprintf('form[action="/admin/albums/%d/delete?from=manager"]', $albumId))->form();
            $token = $deleteForm['_token']->getValue();
            $client->submit($deleteForm, ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);

            $client->submit($deleteForm, ['_token' => $token]);
            self::assertResponseRedirects('/admin/albums');
            $client->followRedirect();
            self::assertSelectorTextContains('.alert-success', 'Its photos were kept.');
            $em->clear();
            self::assertNull($em->find(Album::class, $albumId));
            self::assertNull($em->find(Pictures::class, $pictureId)->getAlbum());
        } finally {
            $connection->rollBack();
        }
    }
}
