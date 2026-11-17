<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class PictureDateFilterTest extends TestCase
{
    public function testSelectedDatesRemainVisible(): void
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

        foreach ([
            ['exact', ['dateExact' => '2026-03-15']],
            ['range', ['dateFrom' => '2026-03-01', 'dateTo' => '2026-03-31']],
            ['range', ['dateFrom' => '2026-03-01']],
            ['range', ['dateTo' => '2026-03-31']],
            ['after', ['dateAfter' => '2026-03-15']],
            ['before', ['dateBefore' => '2026-03-15']],
        ] as [$type, $dates]) {
            $filters = array_fill_keys(['album', 'title', 'keywords', 'dateExact', 'dateFrom', 'dateTo', 'dateAfter', 'dateBefore'], null);
            $filters['dateType'] = $type;
            foreach ($dates as $name => $date) {
                $filters[$name] = new \DateTime($date);
            }

            $html = $twig->render('admin/pictures/index.html.twig', [
                'filters' => $filters,
                'albums' => [],
                'pictures' => [],
            ]);
            $crawler = new Crawler($html);

            self::assertCount(1, $crawler->filter(sprintf('#dateType option[value="%s"][selected]', $type)));
            foreach ($dates as $name => $date) {
                self::assertCount(1, $crawler->filter(sprintf('div[data-date-type="%s"] > input[name="%s"][value="%s"]', $type, $name, $date)));
            }
        }
    }
}
