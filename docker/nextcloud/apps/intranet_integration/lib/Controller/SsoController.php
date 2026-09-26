<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Controller;

use OC\Authentication\Token\IProvider;
use OC\User\Session as OCUserSession;
use OCA\IntranetIntegration\AppInfo\Application;
use OCA\IntranetIntegration\Service\TokenVerifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\Authentication\Token\IToken;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use OCP\User\Backend\IUserBackend;
use OCP\User\Events\BeforeUserLoggedInEvent;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;

/**
 * Automatische Anmeldung des im Intranet erkannten Benutzers.
 *
 * Das Intranet leitet beim Wechsel nach Nextcloud bzw. in eine Office-App mit
 * einem kurzlebigen, einmal verwendbaren HS256-Token hierher (Claims: sub =
 * SamAccountName, target = Ziel unter dem Nextcloud-Webroot). Der Benutzer
 * wird ohne Kennworteingabe angemeldet und zum Ziel weitergeleitet. Ohne
 * gueltiges Token oder unbekanntes Konto: normales Anmeldeformular.
 */
class SsoController extends Controller {
    private const REPLAY_TTL = 300;

    public function __construct(
        IRequest $request,
        private IConfig $config,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private ISession $session,
        private IProvider $tokenProvider,
        private IEventDispatcher $dispatcher,
        private ICacheFactory $cacheFactory,
        private ISecureRandom $random,
        private IURLGenerator $urlGenerator,
        private TokenVerifier $verifier,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    #[UseSession]
    public function login(string $token = ''): RedirectResponse {
        $system = $this->config->getSystemValue('eurooffice', []);
        $secret = is_array($system) ? (string) ($system['jwt_secret'] ?? '') : '';
        $claims = $this->verifier->claims($token, TokenVerifier::ssoKey($secret), TokenVerifier::SSO_AUDIENCE);
        if ($claims === null) {
            $this->logger->warning('Intranet-SSO: ungueltiges oder abgelaufenes Token.', ['app' => Application::APP_ID]);
            return $this->fallback('');
        }

        $target = $this->safeTarget((string) ($claims['target'] ?? ''));
        $uid = (string) ($claims['sub'] ?? '');
        // SamAccountName, bei weiteren Identitaetsquellen mit "@kennung".
        if (preg_match('/^[a-zA-Z0-9._-]{1,64}(@[a-z0-9_]{1,32})?$/', $uid) !== 1 || !$this->consume((string) ($claims['jti'] ?? ''))) {
            $this->logger->warning('Intranet-SSO: Token ohne gueltigen Benutzer oder bereits verwendet.', ['app' => Application::APP_ID]);
            return $this->fallback($target);
        }

        $current = $this->userSession->getUser();
        if ($current !== null && strcasecmp($current->getUID(), $uid) === 0) {
            return new RedirectResponse($target);
        }

        $user = $this->findUser($uid, (string) ($claims['email'] ?? ''))
            ?? $this->provision($uid, (string) ($claims['name'] ?? ''), (string) ($claims['email'] ?? ''));
        if ($user === null || !$user->isEnabled()) {
            $this->logger->warning('Intranet-SSO: Konto {uid} ist in Nextcloud nicht vorhanden oder deaktiviert.', [
                'app' => Application::APP_ID,
                'uid' => $uid,
            ]);
            return $this->fallback($target);
        }

        // Die Identitaet des Intranets ist massgeblich: anderes Konto abmelden.
        if ($current !== null) {
            $this->userSession->logout();
        }

        if (!$this->loginUser($user)) {
            return $this->fallback($target);
        }

        $this->logger->info('Intranet-SSO: {uid} automatisch angemeldet.', ['app' => Application::APP_ID, 'uid' => $user->getUID()]);
        return new RedirectResponse($target);
    }

    private function loginUser(IUser $user): bool {
        if (!$this->userSession instanceof OCUserSession) {
            return false;
        }

        $uid = $user->getUID();
        $backend = $user->getBackend();
        $this->dispatcher->dispatchTyped(new BeforeUserLoggedInEvent($uid, null, $backend instanceof IUserBackend ? $backend : null));

        $this->userSession->setUser($user);
        $this->userSession->completeLogin($user, ['loginName' => $uid, 'password' => '']);
        $this->userSession->createSessionToken($this->request, $uid, $uid);

        // Keine Kennwortbestaetigung fuer sensible Aktionen verlangen: Es gibt
        // kein Nextcloud-Kennwort, die Anmeldung erfolgte im Intranet.
        try {
            $sessionToken = $this->tokenProvider->getToken($this->session->getId());
            $scope = $sessionToken->getScopeAsArray();
            $scope[IToken::SCOPE_SKIP_PASSWORD_VALIDATION] = true;
            $sessionToken->setScope($scope);
            $this->tokenProvider->updateToken($sessionToken);
        } catch (\Throwable $e) {
            $this->logger->info('Intranet-SSO: Sitzungs-Token ohne Kennwortbefreiung.', ['app' => Application::APP_ID, 'exception' => $e]);
        }
        $this->session->set('last-password-confirm', time());

        $this->dispatcher->dispatchTyped(new UserLoggedInEvent($user, $uid, null, false));

        return $this->userSession->isLoggedIn();
    }

    /**
     * Sucht das Konto zum SamAccountName (lokal oder ueber user_ldap) bzw.
     * zu "samaccountname@kennung" bei weiteren Identitaetsquellen.
     */
    private function findUser(string $uid, string $email): ?IUser {
        $user = $this->userManager->get($uid);
        if ($user !== null) {
            return $user;
        }

        // user_ldap legt die Zuordnung erst bei einer Suche an; interne
        // Namen koennen sich in der Grossschreibung unterscheiden.
        foreach ($this->userManager->search($uid, 10) as $candidate) {
            if (strcasecmp($candidate->getUID(), $uid) === 0) {
                return $candidate;
            }
        }

        // Kein Abgleich ueber die E-Mail-Adresse fuer Konten weiterer
        // Identitaetsquellen ("@kennung"): deren Verzeichnis koennte sonst per
        // gleicher Adresse ein Konto der Hauptquelle uebernehmen.
        if (!str_contains($uid, '@') && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $matches = $this->userManager->getByEmail($email);
            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        return null;
    }

    /**
     * Legt optional ein lokales Konto an (sso_autoprovision, z. B. ohne AD).
     */
    private function provision(string $uid, string $displayName, string $email): ?IUser {
        if (!$this->setting('sso_autoprovision', false)) {
            return null;
        }

        try {
            $user = $this->userManager->createUser($uid, $this->random->generate(48));
        } catch (\Throwable $e) {
            $this->logger->warning('Intranet-SSO: Konto {uid} konnte nicht angelegt werden.', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'exception' => $e,
            ]);
            return null;
        }
        if ($user === false || $user === null) {
            return null;
        }

        if ($displayName !== '') {
            $user->setDisplayName($displayName);
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $user->setSystemEMailAddress($email);
        }
        $this->logger->info('Intranet-SSO: Konto {uid} angelegt.', ['app' => Application::APP_ID, 'uid' => $uid]);

        return $user;
    }

    private function consume(string $jti): bool {
        if (preg_match('/^[a-f0-9]{16,64}$/', $jti) !== 1) {
            return false;
        }

        $cache = $this->cacheFactory->isAvailable()
            ? $this->cacheFactory->createDistributed(Application::APP_ID . '-sso')
            : $this->cacheFactory->createLocal(Application::APP_ID . '-sso');
        if ($cache->get($jti) !== null) {
            return false;
        }
        $cache->set($jti, 1, self::REPLAY_TTL);

        return true;
    }

    /**
     * Nur Pfade unterhalb des Nextcloud-Webroots, keine fremden Hosts.
     */
    private function safeTarget(string $target): string {
        $webroot = rtrim($this->urlGenerator->getWebroot(), '/') . '/';
        $default = $this->urlGenerator->linkToDefaultPageUrl();

        if (
            $target === ''
            || !str_starts_with($target, $webroot)
            || str_starts_with($target, '//')
            || preg_match('/[\x00-\x20\x7f\\\\]/', $target) === 1
            || parse_url($target, PHP_URL_SCHEME) !== null
            || parse_url($target, PHP_URL_HOST) !== null
            || preg_match('#(^|/)\.\.?(/|$)#', rawurldecode((string) parse_url($target, PHP_URL_PATH))) === 1
        ) {
            return $default;
        }

        return $target;
    }

    /**
     * Anmeldeformular ohne erneute Umleitung ins Intranet.
     */
    private function fallback(string $target): RedirectResponse {
        $params = ['direct' => '1'];
        if ($target !== '') {
            $params['redirect_url'] = $target;
        }
        return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm', $params));
    }

    private function setting(string $key, bool $default): bool {
        $settings = $this->config->getSystemValue(Application::APP_ID, []);
        $value = is_array($settings) ? ($settings[$key] ?? $default) : $default;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
