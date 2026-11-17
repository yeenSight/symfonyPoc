<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class PicturesControllerTest extends WebTestCase
{
    public function testIndex(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pictures');

        self::assertResponseIsSuccessful();
    }

    public function testAdminPhotoManagementRequiresAuthentication(): void
    {
        $client = static::createClient();

        foreach ([['GET', '/admin/pictures'], ['GET', '/admin/pictures/new'], ['GET', '/admin/pictures/1/edit'], ['POST', '/admin/pictures/1/delete']] as [$method, $uri]) {
            $client->request($method, $uri);
            self::assertResponseRedirects('/login', null, sprintf('%s %s must require login', $method, $uri));
        }
    }

    public function testEditControlsAreHiddenFromAnonymousVisitors(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/pictures');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href^="/admin"]'));
    }

    public function testAdminThumbnailsOpenTheirPictureModal(): void
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['admin/layout.html.twig' => '{% block admin_body %}{% endblock %}']),
            new FilesystemLoader(__DIR__.'/../../templates'),
        ]));
        $twig->addFunction(new TwigFunction('path', fn () => '/admin/pictures'));
        $twig->addFunction(new TwigFunction('csrf_token', fn () => 'token'));
        $twig->addGlobal('app', new class {
            public function flashes(string $type): array
            {
                return [];
            }
        });

        $pictures = [
            ['id' => 1, 'title' => 'First', 'description' => 'First description', 'url' => '/first.jpg', 'album' => null, 'uploadDate' => new \DateTime('2026-03-01')],
            ['id' => 2, 'title' => 'Second', 'description' => 'Second description', 'url' => '/second.jpg', 'album' => null, 'uploadDate' => new \DateTime('2026-03-02')],
        ];
        $filters = array_fill_keys(['album', 'title', 'keywords', 'dateType', 'dateExact', 'dateFrom', 'dateTo', 'dateAfter', 'dateBefore'], null);
        $crawler = new Crawler($twig->render('admin/pictures/index.html.twig', [
            'filters' => $filters,
            'albums' => [],
            'pictures' => $pictures,
            'currentPage' => 1,
            'totalPages' => 1,
            'totalPictures' => 2,
        ]));

        foreach ($pictures as $picture) {
            $id = $picture['id'];
            self::assertCount(1, $crawler->filter(sprintf('button[data-bs-toggle="modal"][data-bs-target="#picture-modal-%d"] img[src="%s"]', $id, $picture['url'])));
            self::assertCount(1, $crawler->filter(sprintf('#picture-modal-%d img[src="%s"]', $id, $picture['url'])));
            self::assertStringContainsString($picture['description'], $crawler->filter('#picture-modal-'.$id)->text());
        }
    }
}
