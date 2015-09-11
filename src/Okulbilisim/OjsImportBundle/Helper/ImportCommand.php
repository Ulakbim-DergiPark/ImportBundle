<?php

namespace Okulbilisim\OjsImportBundle\Helper;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ImportCommand extends ContainerAwareCommand
{
    /**
     * @var Connection
     */
    protected $connection;

    protected function configure()
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'User ID')
            ->addArgument('host', InputArgument::REQUIRED, 'Hostname of PKP/OJS database server')
            ->addArgument('username', InputArgument::REQUIRED, 'Username for PKP/OJS database server')
            ->addArgument('password', InputArgument::REQUIRED, 'Password for PKP/OJS database server')
            ->addArgument('database', InputArgument::REQUIRED, 'Name of PKP/OJS database')
            ->addArgument('driver', InputArgument::OPTIONAL, 'Database driver', 'pdo_mysql');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $parameters = [
            'host' => $input->getArgument('host'),
            'user' => $input->getArgument('username'),
            'password' => $input->getArgument('password'),
            'dbname' => $input->getArgument('database'),
            'driver' => $input->getArgument('driver'),
        ];

        $this->connection = $this
            ->getContainer()
            ->get('doctrine.dbal.connection_factory')
            ->createConnection($parameters);
    }

    protected function throwInvalidArgumentException($message)
    {
        throw new InvalidArgumentException($message);
    }
}