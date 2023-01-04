<?php
/**
 * Nextcloud - VO Federation
 *
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @author Sandro Mesterheide <sandro.mesterheide@extern.publicplan.de>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\VO_Federation\AppInfo;

use OC\EventDispatcher\EventDispatcher;
use OCA\VO_Federation\Backend\GroupBackend;
use OCA\VO_Federation\FederatedGroupShareProvider;
use OCA\VO_Federation\OCM\CloudGroupFederationProviderFiles;
use OCA\VO_Federation\Service\GroupsService;
use OCA\VO_Federation\Service\ProviderService;
use OCA\VO_Federation\Listeners\LoadAdditionalScriptsListener;
use OCA\VO_Federation\Middleware\ShareAPIMiddleware;
use OCA\VO_Federation\Middleware\ShareAPIMiddleware2;
use OCA\VO_Federation\Db\ShareMapper;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\IAppContainer;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Share\IManager;
use OCP\User\Events\UserLoggedInEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OC\AppFramework\Middleware\MiddlewareDispatcher;
		

/**
 * Class Application
 *
 * @package OCA\VO_Federation\AppInfo
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'vo_federation';
	/**
	 * Constructor
	 *
	 * @param array $urlParams
	 */
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);	
	}

	public function register(IRegistrationContext $context): void {
		// Register the composer autoloader for packages shipped by this app, if applicable
		//include_once __DIR__ . '/../../vendor/autoload.php';
		
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadAdditionalScriptsListener::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (
			IGroupManager $groupManager,
			GroupBackend $groupBackend,
			IManager $shareManager,
			ICloudFederationProviderManager $federationProviderManager,
			IAppContainer $appContainer
		) {
			$groupManager->addBackend($groupBackend);
			$shareManager->registerShareProvider(FederatedGroupShareProvider::class);
			$federationProviderManager->addCloudFederationProviderForShareType('file-federated_group', 'file', ['federated_group'],
				'Federated Files Sharing (federated_group)',
				function () use ($appContainer): CloudGroupFederationProviderFiles {
					return $appContainer->get(CloudGroupFederationProviderFiles::class);
				});
		});

		$context->injectFn(function (EventDispatcher $dispatcher,
			IConfig $config,
			ProviderService $providerService,
			GroupsService $groupsService) {
			/*
			 * @todo move the OCP events and then move the registration to `register`
			 */
			$dispatcher->addListener(
				UserLoggedInEvent::class,
				function (\OCP\User\Events\UserLoggedInEvent $event) use ($config, $providerService, $groupsService) {
					$providers = $providerService->getProvidersWithSettings();
					foreach ($providers as $provider) {
						$providerId = $provider['id'];
						$userId = $event->getUser()->getUID();
						try {
							$session = $providerService->getProviderSession($userId, $providerId);
							if ($session !== null) {
								$groupsService->syncUser($userId, $providerId);
							}
						} catch (DoesNotExistException $e) {
						} catch (\Exception $other) {
							// TODO: Handle server availability
						}
					}
				}
			);
		});
		
		$filesSharingAppContainer = \OC::$server->getRegisteredAppContainer('files_sharing');
		$filesSharingAppContainer->registerMiddleWare(ShareAPIMiddleware::class);
	}
}
