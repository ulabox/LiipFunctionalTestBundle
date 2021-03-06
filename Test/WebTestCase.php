<?php

/*
 * This file is part of the Liip/FunctionalTestBundle
 *
 * (c) Lukas Kahwe Smith <smith@pooteeweet.org>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Liip\FunctionalTestBundle\Test;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as BaseWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;

use Symfony\Bundle\DoctrineFixturesBundle\Common\DataFixtures\Loader as SymfonyFixturesLoader;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;

use Doctrine\DBAL\Driver\PDOSqlite\Driver as SqliteDriver;

use Doctrine\ORM\Tools\SchemaTool;

use Doctrine\Bundle\FixturesBundle\Common\DataFixtures\Loader as DoctrineFixturesLoader;

/**
 * @author Lea Haensenberger
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 */
abstract class WebTestCase extends BaseWebTestCase
{
    protected $environment = 'test';
    protected $containers;
    protected $kernelDir;
    protected $maxMemory = 5242880; // 5 * 1024 * 1024 KB
    protected static $classes;
    protected static $backup; // filename of the backup DB
    private $firewallLogins = array();

    /**
     * Recover the backup filename
     *
     * @return string backup filename
     */
    static protected function getBackup()
    {
        return self::$backup;
    }

    static protected function getKernelClass()
    {
        $dir = isset($_SERVER['KERNEL_DIR']) ? $_SERVER['KERNEL_DIR'] : self::getPhpUnitXmlDir();

        list($appname) = explode('\\', get_called_class());

        $class = $appname.'Kernel';
        $file = $dir.'/'.strtolower($appname).'/'.$class.'.php';
        if (!file_exists($file)) {
            return parent::getKernelClass();
        }
        require_once $file;

        return $class;
    }

    /**
     * Creates a mock object of a service identified by its id.
     *
     * @param string $id
     * @return PHPUnit_Framework_MockObject_MockBuilder
     */
    protected function getServiceMockBuilder($id)
    {
        $service = $this->getContainer()->get($id);
        $class = get_class($service);
        return $this->getMockBuilder($class)->disableOriginalConstructor();
    }

    /**
     * Builds up the environment to run the given command.
     *
     * @param string $name
     * @param array $params
     * @return string
     */
    protected function runCommand($name, array $params = array())
    {
        array_unshift($params, $name);

        $kernel = $this->createKernel(array('environment' => $this->environment));
        $kernel->boot();

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput($params);
        $input->setInteractive(false);

        $fp = fopen('php://temp/maxmemory:'.$this->maxMemory, 'r+');
        $output = new StreamOutput($fp);

        $application->run($input, $output);

        rewind($fp);
        return stream_get_contents($fp);
    }

    /**
     * Get an instance of the dependency injection container.
     * (this creates a kernel *without* parameters).
     * @return object
     */
    protected function getContainer()
    {
        if (!empty($this->kernelDir)) {
            $tmpKernelDir = isset($_SERVER['KERNEL_DIR']) ? $_SERVER['KERNEL_DIR'] : null;
            $_SERVER['KERNEL_DIR'] = getcwd().$this->kernelDir;
        }

        if (empty($this->containers[$this->kernelDir])) {
            $options = array(
                'environment' => $this->environment
            );
            $kernel = $this->createKernel($options);
            $kernel->boot();

            $this->containers[$this->kernelDir] = $kernel->getContainer();
        }

        if (isset($tmpKernelDir)) {
            $_SERVER['KERNEL_DIR'] = $tmpKernelDir;
        }

        return $this->containers[$this->kernelDir];
    }

    /**
     * Set the database to the provided fixtures.
     *
     * Drops the current database and then loads fixtures using the specified
     * classes. The parameter is a list of fully qualified class names of
     * classes that implement Doctrine\Common\DataFixtures\FixtureInterface
     * so that they can be loaded by the DataFixtures Loader::addFixture
     *
     * When using SQLite this method will automatically make a copy of the
     * loaded schema and fixtures which will be restored automatically in
     * case the same fixture classes are to be loaded again, but no executor
     * instance will be returned in this case.
     *
     * Depends on the doctrine data-fixtures library being available in the
     * class path.
     *
     * @param string $omName       The name of object manager to use
     * @param string $registryName The service id of manager registry to use
     * @param int    $purgeMode    Sets the ORM purge mode
     *
     * @return null|Doctrine\Common\DataFixtures\Executor\AbstractExecutor
     */
    protected function loadFixtures(array $classNames, $omName = null, $registryName = 'doctrine', $purgeMode = null)
    {
        $container = $this->getContainer();
        $registry = $container->get($registryName);
        if ($registry instanceof ManagerRegistry) {
            $om = $registry->getManager($omName);
            $type = $registry->getName();
        } else {
            $om = $registry->getEntityManager($omName);
            $type = 'ORM';
        }

        $executorClass = 'Doctrine\\Common\\DataFixtures\Executor\\'.$type.'Executor';

        if ('ORM' === $type) {
            $connection = $om->getConnection();

            $params = $connection->getParams();
            $name = isset($params['path']) ? $params['path'] : $params['dbname'];

            $metadatas = $om->getMetadataFactory()->getAllMetadata();

            if ($connection->getDriver() instanceOf SqliteDriver && $container->getParameter('liip_functional_test.cache_sqlite_db')) {
                if (isset(static::$backup) && file_exists(static::$backup)) {
                    copy(static::$backup, $name);

                    return;
                }
            }

            // TODO: handle case when using persistent connections. Fail loudly?
            $schemaTool = new SchemaTool($om);
            $schemaTool->dropDatabase($name);
            if (!empty($metadatas)) {
                $schemaTool->createSchema($metadatas);
            }

            $executor = new $executorClass($om);
        }

        if (empty($executor)) {
            $purgerClass = 'Doctrine\\Common\\DataFixtures\Purger\\'.$type.'Purger';
            $purger = new $purgerClass();
            if (null !== $purgeMode) {
                $purger->setPurgeMode($purgeMode);
            }

            $executor = new $executorClass($om, $purger);
            $executor->purge();
        }

        $loader = $this->getFixtureLoader($container, $classNames);

        try {
            $executor->execute($loader->getFixtures(), true);
        } catch (\Exception $e) {
            echo 'Error executing fixtures: ' . $e->getMessage() . PHP_EOL;
           // print_r($e->getTrace());
            unlink($name);
            exit();
        }

        if (isset(static::$backup)) {
            copy($name, static::$backup);
        }

        return $executor;
    }

    /**
     * Retrieve Doctrine DataFixtures loader.
     *
     * @param ContainerInterface $container
     * @param array              $classNames
     *
     * @return \Symfony\Bundle\DoctrineFixturesBundle\Common\DataFixtures\Loader
     */
    protected function getFixtureLoader(ContainerInterface $container, array $classNames)
    {
        $loader    = class_exists('Doctrine\Bundle\FixturesBundle\Common\DataFixtures\Loader')
            ? new DoctrineFixturesLoader($container)
            : new SymfonyFixturesLoader($container);

        foreach ($classNames as $className) {
            $this->loadFixtureClass($loader, $className);
        }

        return $loader;
    }

    /**
     * Load a data fixture class.
     *
     * @param \Symfony\Bundle\DoctrineFixturesBundle\Common\DataFixtures\Loader $loader
     * @param string                                                            $className
     */
    protected function loadFixtureClass($loader, $className)
    {
        $fixture = new $className();

        $loader->addFixture($fixture);

        if ($fixture instanceof DependentFixtureInterface) {
            foreach ($fixture->getDependencies() as $dependency) {
                $this->loadFixtureClass($loader, $dependency);
            }
        }
    }

    /**
     * Creates an instance of a lightweight Http client.
     *
     * If $authentication is set to 'true' it will use the content of
     * 'liip_functional_test.authentication' to log in.
     *
     * @param boolean $authentication
     *
     * @return Client
     */
    protected function makeClient($authentication = false)
    {
        $params = array();
        if ($authentication) {
            if ($authentication === true) {
                $authentication = $this->getContainer()->getParameter('liip_functional_test.authentication');
            }

            $params = array('PHP_AUTH_USER' => $authentication['username'], 'PHP_AUTH_PW' => $authentication['password']);
        }

        $client = $this->createClient(array('environment' => $this->environment), $params);

        if ($this->firewallLogins) {
            // has to be set otherwise "hasPreviousSession" in Request returns false.
            $options = self::$kernel->getContainer()->getParameter('session.storage.options');
            if (!$options || !isset($options['name'])) {
                throw new \InvalidArgumentException("Missing session.storage.options#name");
            }

            $client->getCookieJar()->set(new Cookie($options['name'], true));

            $session = self::$kernel->getContainer()->get('session');
            foreach ($this->firewallLogins as $firewallName => $user) {
                $token = new UsernamePasswordToken($user, null, $firewallName, $user->getRoles());
                $session->set('_security_' . $firewallName, serialize($token));
            }
        }

        return $client;
    }

    /**
     * Extracts the location from the given route.
     *
     * @param string $route  The name of the route
     * @param array  $params Set of parameters
     *
     * @return string
     */
    protected function getUrl($route, $params = array())
    {
        return $this->getContainer()->get('router')->generate($route, $params);
    }

    /**
     * Checks the success state of a response
     *
     * @param Response $response Response object
     * @param bool     $success  Success to define whether the response is expected to be successful
     *
     * @return void
     */
    public function isSuccessful($response, $success = true, $type = 'text/html')
    {
        try {
            $crawler = new Crawler();
            $crawler->addContent($response->getContent(), $type);
            if (! count($crawler->filter('title'))) {
                $title = '['.$response->getStatusCode().'] - '.$response->getContent();
            } else {
                $title = $crawler->filter('title')->text();
            }
        } catch (\Exception $e) {
            $title = $e->getMessage();
        }

        if ($success) {
            $this->assertTrue($response->isSuccessful(), 'The Response was not successful: '.$title);
        } else {
            $this->assertFalse($response->isSuccessful(), 'The Response was successful: '.$title);
        }
    }

    /**
     * Executes a request on the given url and returns the response contents.
     *
     * This method also asserts the request was successful.
     *
     * @param string $path           Path of the requested page
     * @param string $method         The HTTP method to use, defaults to GET
     * @param bool   $authentication Whether to use authentication, defaults to false
     * @param bool   $success        Success to define whether the response is expected to be successful
     *
     * @return string
     */
    public function fetchContent($path, $method = 'GET', $authentication = false, $success = true)
    {
        $client = $this->makeClient($authentication);
        $client->request($method, $path);

        $content = $client->getResponse()->getContent();
        if (is_bool($success)) {
            $this->isSuccessful($client->getResponse(), $success);
        }

        return $content;
    }

    /**
     * Executes a request on the given url and returns a Crawler object.
     *
     * This method also asserts the request was successful.
     *
     * @param string $path path of the requested page
     * @param string $method The HTTP method to use, defaults to GET
     * @param bool $authentication Whether to use authentication, defaults to false
     * @param bool $success to define whether the response is expected to be successful
     * @return Crawler
     */
    public function fetchCrawler($path, $method = 'GET', $authentication = false, $success = true)
    {
        $client = $this->makeClient($authentication);
        $crawler = $client->request($method, $path);

        $this->isSuccessful($client->getResponse(), $success);

        return $crawler;
    }

    /**
     * @param UserInterface $user
     * @return WebTestCase
     */
    public function loginAs(UserInterface $user, $firewallName)
    {
        $this->firewallLogins[$firewallName] = $user;
        return $this;
    }

    /**
     * Set up before class method. Delete the backup db file to avoid reusing
     * and old backup when executing the tests again
     */
    public static function setUpBeforeClass()
    {
        if(isset(static::$backup) && (file_exists(static::$backup))) {
            unlink(static::$backup);
        }
    }
}
