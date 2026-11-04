<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
}
