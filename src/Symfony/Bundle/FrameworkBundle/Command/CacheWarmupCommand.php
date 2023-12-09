<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Command;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\DependencyInjection\Dumper\Preloader;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerAggregate;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Warmup the cache.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 *
 * @final
 */
#[AsCommand(name: 'cache:warmup', description: 'Warm up an empty cache')]
class CacheWarmupCommand extends Command
{
    public function __construct(
        private readonly CacheWarmerAggregate $cacheWarmer,
        private readonly ServiceProviderInterface $cacheWarmers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDefinition([
                new InputOption('no-optional-warmers', '', InputOption::VALUE_NONE, 'Skip optional cache warmers (faster)'),
                new InputArgument('warmer-name', InputArgument::OPTIONAL, 'Specific warmer name to warmup')
            ])
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command warms up the cache.

Before running this command, the cache must be empty.

EOF
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $kernel = $this->getApplication()->getKernel();
        $io->comment(sprintf('Warming up the cache for the <info>%s</info> environment with debug <info>%s</info>', $kernel->getEnvironment(), var_export($kernel->isDebug(), true)));
        $cacheDir = $kernel->getContainer()->getParameter('kernel.cache_dir');
        $buildDir = $kernel->getContainer()->getParameter('kernel.build_dir');

        if ($name = $input->getArgument('warmer-name')) {
            if (!$this->cacheWarmers->has($name)) {
                $io->error(sprintf('Cache warmer "%s" does not exist.', $name));

                return 1;
            }

            $this->cacheWarmers->get($name)->warmUp($cacheDir, $buildDir);

            $io->success(sprintf('Cache warmer "%s" was successfully warmed.', $name));

            return 0;
        }

        if (!$input->getOption('no-optional-warmers')) {
            $this->cacheWarmer->enableOptionalWarmers();
        }

        if ($kernel instanceof WarmableInterface) {
            $kernel->warmUp($cacheDir);
        }

        $preload = $this->cacheWarmer->warmUp($cacheDir);

        if ($preload && $cacheDir === $buildDir && file_exists($preloadFile = $buildDir.'/'.$kernel->getContainer()->getParameter('kernel.container_class').'.preload.php')) {
            Preloader::append($preloadFile, $preload);
        }

        $io->success(sprintf('Cache for the "%s" environment (debug=%s) was successfully warmed.', $kernel->getEnvironment(), var_export($kernel->isDebug(), true)));

        return 0;
    }
}
