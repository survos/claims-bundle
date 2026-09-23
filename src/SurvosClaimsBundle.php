<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle;

use Survos\ClaimsBundle\Command\ClaimsExportCommand;
use Survos\ClaimsBundle\Command\ClaimsFetchCommand;
use Survos\ClaimsBundle\Command\ClaimsUsageCommand;
use Survos\ClaimsBundle\Command\ClaimsImportCommand;
use Survos\ClaimsBundle\Repository\ClaimRepository;
use Survos\ClaimsBundle\Repository\ClaimRunRepository;
use Survos\ClaimsBundle\Service\ClaimAggregator;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\ClaimsBundle\Service\ClaimProjector;
use Survos\ClaimsBundle\Service\ApiClaimReader;
use Survos\ClaimsBundle\Service\ClaimReader;
use Survos\ClaimsBundle\Service\ClaimReaderInterface;
use Survos\ClaimsBundle\Service\ClaimsVaultWriter;
use Survos\ClaimsBundle\Twig\Components\ClaimsList;
use Survos\ClaimsBundle\Twig\Components\ClaimsSummary;
use Survos\ClaimsBundle\Twig\ClaimConstantsExtension;
use Survos\ClaimsBundle\Twig\ClaimFunctionsExtension;
use Survos\ClaimsBundle\Twig\Components\OcrClaimsPanel;
use Survos\ClaimsBundle\Twig\Components\SourceClaims;
use Survos\DatasetBundle\Service\DataPaths;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class SurvosClaimsBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('reader_only')
                    ->info('Reader-only consumer: read mediary\'s central claims via ClaimReader, do NOT map the Claim entity (no local claim table) or register the writer services. Default false = writer (entities + ingestor).')
                    ->defaultFalse()
                ->end()
                ->scalarNode('entity_manager')
                    ->info('Writer EM for the Claim/ClaimRun entities. Default "default" = the app DB (current behavior). Set to a named EM (e.g. "claims", backed by CLAIMS_DATABASE_URL) to write claims to a SHARED central DB instead. The named EM must be defined in the app doctrine config (connection only — the bundle maps the entities to it).')
                    ->defaultValue('default')
                ->end()
                ->arrayNode('list_predicates')
                    ->info('Predicates the aggregator projects as a list (keywords, places, etc.). Consumers register their own.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('model_rates')
                    ->info('USD per 1M tokens per model, for claims:usage costing, e.g. "gpt-4o-mini": {input: 0.15, output: 0.60}. Rates are config, never hard-coded: they change, they differ per account, and a stale number printed as fact is worse than no number. Without an entry the command still reports tokens and simply leaves cost blank. Keys match ClaimRun::$model, which records the provider\'s own id ("gpt-4o-mini-2024-07-18"); a prefix match is used so an alias entry covers its dated versions.')
                    ->useAttributeAsKey('model')
                    // Model ids contain hyphens ("gpt-4o-mini"); Symfony's default key
                    // normalisation rewrites them to underscores, so every lookup missed
                    // and every cost column printed blank.
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->floatNode('input')->defaultValue(0.0)->info('USD per 1M input tokens.')->end()
                            ->floatNode('output')->defaultValue(0.0)->info('USD per 1M output tokens.')->end()
                            ->floatNode('per_call')->defaultValue(0.0)->info('USD per call, for page/request-priced models (Mistral OCR) that report no tokens.')->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->enumNode('reader')
                    ->info('How ClaimReaderInterface reaches the central claims store. "dbal" (default) opens a Postgres connection from CLAIMS_DATABASE_URL — the app needs network access to the DB, a readonly role, and a credential to rotate. "api" calls mediary over HTTP instead, so only mediary touches the database and a reader app holds a URL + token. Writers are unaffected: ClaimIngestor always writes over the EM.')
                    ->values(['dbal', 'api'])
                    ->defaultValue('dbal')
                ->end()
                ->arrayNode('api')
                    ->info('Settings for reader: api. Ignored when reader: dbal.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('base_uri')
                            ->info('Mediary base URI, e.g. https://mediary.survos.com. Empty leaves ApiClaimReader::isAvailable() false so callers degrade instead of erroring.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('token')
                            ->info('Bearer token sent to the claims API. Reads are not public: claims can contain unpublished AI output.')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()
            ->defaults()
                ->autowire()
                ->autoconfigure();

        // Always available: read access to the central claim store + display helpers that don't
        // touch the ORM. ClaimReader uses the `claims` connection (registered in prependExtension
        // when CLAIMS_DATABASE_URL is set — the SAME var the writer uses; read-only is enforced by
        // the Postgres role in the DSN, not a second var). Injected optionally so
        // ClaimReader::isAvailable() can guard callers when it's absent.
        $services->set(ClaimReader::class)
            ->arg('$connection', service('doctrine.dbal.claims_connection')->ignoreOnInvalid());

        // The HTTP reader is always registered but only aliased when reader: api. Registering it
        // unconditionally keeps the wiring symmetric and lets an app inject it explicitly (e.g. a
        // command that compares the two transports) without flipping global config.
        $services->set(ApiClaimReader::class)
            ->arg('$baseUri', $config['api']['base_uri'] ?? null)
            ->arg('$token', $config['api']['token'] ?? null)
            ->arg('$logger', service('logger')->ignoreOnInvalid());

        // Consumers type-hint the INTERFACE; this alias decides the transport. ClaimReader::class
        // stays a real service so existing concrete type-hints keep resolving (they get DBAL, which
        // is what they always got) — switching an app to the API is then a config change plus a
        // type-hint change, never a silent behaviour swap under a name that says "DBAL".
        $services->alias(ClaimReaderInterface::class, 'api' === $config['reader']
            ? ApiClaimReader::class
            : ClaimReader::class);
        // Reader-side fetch service: dumps a dataset's claims to the vault claims.jsonl (ClaimReader,
        // no ORM), so it works on reader-only consumers too. Single owner of the vault row shape;
        // claims:fetch and dataset:assemble both call it. DataPaths optional (graceful without dataset-bundle).
        $services->set(ClaimsVaultWriter::class)
            ->arg('$dataPaths', service(DataPaths::class)->ignoreOnInvalid());
        $services->set(ClaimsFetchCommand::class);

        // Reader-side like claims:fetch, so it works on reader-only consumers too: it reads
        // claim_run straight off the claims connection rather than through the ORM.
        $services->set(ClaimsUsageCommand::class)
            ->arg('$claimsConnection', service('doctrine.dbal.claims_connection')->ignoreOnInvalid())
            ->arg('$modelRates', $config['model_rates']);
        $services->set(ClaimProjector::class)->autowire()->autoconfigure();
        $services->set(SourceClaims::class);
        $services->set(ClaimConstantsExtension::class);
        $services->set(ClaimFunctionsExtension::class)->autoconfigure();

        // Writer side: the ORM Claim/ClaimRun entities (mapped in prependExtension), the
        // ingestor, repositories, and ORM-backed components/commands. Skipped in reader-only
        // consumers (survos_claims.reader_only: true) so they get no `claim` table in their
        // schema — they READ mediary's claims via ClaimReader instead. Default is writer
        // (unchanged for mediary/md/pipeline/ssai).
        if (!$config['reader_only']) {
            $services->set(ClaimRepository::class);
            $services->set(ClaimRunRepository::class);
            $ingestor = $services->set(ClaimIngestor::class)
                ->arg('$dataPaths', service(DataPaths::class)->ignoreOnInvalid());
            // Writing to a named (shared) EM: inject it explicitly — ClaimIngestor injects
            // EntityManagerInterface, which otherwise autowires to the DEFAULT EM. The repositories
            // (ServiceEntityRepository) resolve their EM from the Claim mapping, so moving the
            // mapping to the named EM (prependExtension) carries them along automatically.
            if (($config['entity_manager'] ?? 'default') !== 'default') {
                $ingestor->arg('$em', service('doctrine.orm.' . $config['entity_manager'] . '_entity_manager'));
            }
            $services->set(ClaimAggregator::class)
                ->arg('$listPredicates', $config['list_predicates']);
            $services->set(ClaimsExportCommand::class);
            $services->set(ClaimsImportCommand::class);
            $services->set(ClaimsList::class);
            $services->set(ClaimsSummary::class);
            $services->set(OcrClaimsPanel::class);

            if (class_exists(\Survos\TablerBundle\Event\MenuEvent::class)) {
                $services->set(\Survos\ClaimsBundle\Menu\ClaimsMenuSubscriber::class)
                    ->autowire()
                    ->autoconfigure();
            }
        }
    }

    /**
     * Read `survos_claims.reader_only` at prepend time (before config is processed) — the
     * standard ContainerBuilder API for peeking a bundle's own config in prependExtension.
     * Mirrors the `reader_only` config node consumed via $config in loadExtension.
     */
    private static function isReaderOnly(ContainerBuilder $builder): bool
    {
        $readerOnly = false;
        foreach ($builder->getExtensionConfig('survos_claims') as $cfg) {
            if (\is_array($cfg) && \array_key_exists('reader_only', $cfg)) {
                $readerOnly = (bool) $cfg['reader_only'];
            }
        }

        return $readerOnly;
    }

    /** Read `survos_claims.entity_manager` at prepend time (mirrors {@see isReaderOnly}). */
    private static function entityManagerName(ContainerBuilder $builder): string
    {
        $em = 'default';
        foreach ($builder->getExtensionConfig('survos_claims') as $cfg) {
            if (\is_array($cfg) && \array_key_exists('entity_manager', $cfg)
                && \is_string($cfg['entity_manager']) && $cfg['entity_manager'] !== '') {
                $em = $cfg['entity_manager'];
            }
        }

        return $em;
    }

    /** True when CLAIMS_DATABASE_URL is set (non-empty) — dotenv has populated $_ENV by compile time. */
    private static function claimsConfigured(): bool
    {
        $url = $_ENV['CLAIMS_DATABASE_URL']
            ?? $_SERVER['CLAIMS_DATABASE_URL']
            ?? getenv('CLAIMS_DATABASE_URL');

        return is_string($url) && $url !== '';
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $readerOnly = self::isReaderOnly($builder);

        if (!$readerOnly) {
            // Writer side owns the Claim/ClaimRun ORM entities. Reader-only consumers skip
            // this so the Claim table never appears in their schema (they read mediary's).
            $mapping = [
                'SurvosClaimsBundle' => [
                    'is_bundle' => false,
                    'type' => 'attribute',
                    'dir' => \dirname(__DIR__) . '/src/Entity',
                    'prefix' => 'Survos\\ClaimsBundle\\Entity',
                    'alias' => 'Claims',
                ],
            ];
            // Attach the mapping to the configured EM: the default EM (current behavior) or a named EM
            // (e.g. "claims" → CLAIMS_DATABASE_URL) so claims write to a SHARED central DB. The named
            // EM's connection is defined by the app; we only attach the entity mapping to it here.
            $emName = self::entityManagerName($builder);
            $builder->prependExtensionConfig('doctrine', [
                'orm' => $emName === 'default'
                    ? ['mappings' => $mapping]
                    : ['entity_managers' => [$emName => ['mappings' => $mapping]]],
            ]);
        }

        // DBAL connection to the central claims DB for ClaimReader (claims:fetch, display helpers).
        // ONE env var — CLAIMS_DATABASE_URL — for both read and write; "read-only" is enforced by the
        // Postgres role in the DSN (a reader app points CLAIMS_DATABASE_URL at a readonly database role, so a
        // write fails at the DB). Registered for BOTH readers (zm) and writers (md) whenever the var is
        // set. Named connection (default_connection untouched), no ORM mapping → invisible to
        // schema/migration tooling; ClaimReader takes it via ignoreOnInvalid. Guarded on the env so
        // apps that don't use claims (mus, scan) don't get a connection pointing at a missing DSN.
        if (self::claimsConfigured()) {
            $builder->prependExtensionConfig('doctrine', [
                'dbal' => [
                    'connections' => [
                        'claims' => [
                            'url' => '%env(resolve:CLAIMS_DATABASE_URL)%',
                        ],
                    ],
                ],
            ]);
        }

        // Expose bundle templates under @SurvosClaims for component + override.
        $builder->prependExtensionConfig('twig', [
            'paths' => [
                \dirname(__DIR__) . '/templates' => 'SurvosClaims',
            ],
        ]);
    }
}
