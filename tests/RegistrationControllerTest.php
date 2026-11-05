<?php
namespace App\Tests;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RegistrationControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Ensure we have a clean database
        $container = static::getContainer();

        /** @var EntityManager $em */
        $em = $container->get('doctrine')->getManager();
        $this->userRepository = $container->get(UserRepository::class);

        foreach ($this->userRepository->findAll() as $user) {
            $em->remove($user);
        }

        $em->flush();
    }

    public function testRegisterRequiresAuthentication(): void
    {
        $this->client->request('GET', '/register');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testRegister(): void
    {
        $admin = (new User())
            ->setEmail('admin@example.com')
            ->setPassword('not-used');
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->persist($admin);
        $em->flush();

        $this->client->loginUser($admin);

        $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Register');

        $this->client->submitForm('Register', [
            'registration_form[email]' => 'me@example.com',
            'registration_form[plainPassword]' => 'password',
        ]);

        self::assertResponseRedirects('/register');
        self::assertCount(2, $this->userRepository->findAll());
        self::assertNotNull($this->userRepository->findOneBy(['email' => 'me@example.com']));
    }
}
