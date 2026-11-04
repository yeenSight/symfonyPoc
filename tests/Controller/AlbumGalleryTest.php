<?php

namespace App\Tests\Controller;

use App\Entity\Album;
use App\Entity\Pictures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AlbumGalleryTest extends WebTestCase
{
    public function testAlbumCardShowsLatestPictureAndGalleryListsAll(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $album = (new Album())->setName('Holidays')->setDescription('Summer trip');
        $em->persist($album);
        foreach ([['old', '2026-01-01'], ['new', '2026-03-01']] as [$name, $date]) {
            $picture = (new Pictures())->setTitle($name)->setDescription('d')->setMetadata('m')
                ->setUrl('/uploads/pictures/'.$name.'.jpg')->setUploadDate(new \DateTime($date));
            $picture->setAlbum($album);
            $em->persist($picture);
        }
        $em->flush();

        $crawler = $client->request('GET', '/pictures');
        self::assertResponseIsSuccessful();
        self::assertSame('/uploads/pictures/new.jpg', $crawler->filter('.card img')->first()->attr('src'));
        self::assertSelectorTextContains('.card-title', 'Holidays');
        self::assertSelectorTextContains('.card-text', 'Summer trip');
        self::assertCount(0, $crawler->filter('a[href^="/admin"]'));

        $client->click($crawler->selectLink('Holidays')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.card img');
        self::assertSelectorCount(2, '.modal[id^="picture-modal-"]');
        self::assertSelectorNotExists('a[href^="/admin"]');

        $em->createQuery('DELETE FROM App\Entity\Pictures')->execute();
        $em->createQuery('DELETE FROM App\Entity\Album')->execute();
    }

    public function testPicturesWithoutAlbumAreGroupedInOthers(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        foreach ([['a', '2026-01-01'], ['b', '2026-02-01']] as [$name, $date]) {
            $em->persist((new Pictures())->setTitle($name)->setDescription('d')->setMetadata('m')
                ->setUrl('/uploads/pictures/'.$name.'.jpg')->setUploadDate(new \DateTime($date)));
        }
        $em->flush();

        $crawler = $client->request('GET', '/pictures');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-title', 'Others');
        self::assertSame('/uploads/pictures/b.jpg', $crawler->filter('.card img')->first()->attr('src'));

        $client->click($crawler->selectLink('Others')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Others');
        self::assertSelectorCount(2, '.card img');

        $em->createQuery('DELETE FROM App\Entity\Pictures')->execute();
    }

    public function testAlbumAdminRoutesRequireLogin(): void
    {
        $client = static::createClient();
        foreach ([['GET', '/admin/albums/new'], ['GET', '/admin/albums/1/edit'], ['POST', '/admin/albums/1/delete']] as [$method, $uri]) {
            $client->request($method, $uri);
            self::assertResponseRedirects('/login', null, "$method $uri");
        }
    }
}
