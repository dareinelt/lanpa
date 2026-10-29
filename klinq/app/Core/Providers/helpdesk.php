<?php

declare(strict_types=1);

use App\Controllers\Helpdesk\HelpdeskAdminController;
use App\Controllers\Helpdesk\HelpdeskApiController;
use App\Controllers\Helpdesk\HelpdeskDashboardController;
use App\Controllers\Helpdesk\HelpdeskReportController;
use App\Controllers\Helpdesk\KnowledgeBaseController;
use App\Controllers\Helpdesk\PortalController;
use App\Controllers\Helpdesk\HelpdeskSupportShiftController;
use App\Controllers\Helpdesk\QuickReportController;
use App\Controllers\Helpdesk\TicketAttachmentController;
use App\Controllers\Helpdesk\TaskController;
use App\Controllers\Helpdesk\TicketController;
use App\Controllers\HelpdeskAiController;
use App\Controllers\HelpdeskMailboxController;
use App\Controllers\HelpdeskOutboundMailController;
use App\Core\Config;
use App\Core\Container;
use App\Core\Logger;
use App\Core\View;
use App\Repositories\AssetRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\HelpdeskSupportShiftRepository;
use App\Repositories\KnowledgeBaseRepository;
use App\Repositories\LocationRepository;
use App\Repositories\TaskRepository;
use App\Repositories\TicketAttachmentRepository;
use App\Repositories\TicketCategoryRepository;
use App\Repositories\TicketCommentRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRelationRepository;
use App\Repositories\TicketReportRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketRuleRepository;
use App\Repositories\TicketSlaRepository;
use App\Repositories\TicketTagRepository;
use App\Repositories\TicketTemplateRepository;
use App\Repositories\TicketWorklogRepository;
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Security\Permissions;
use App\Services\Ad\AdUserLookupService;
use App\Services\AuditLogService;
use App\Services\DocumentService;
use App\Services\Helpdesk\Ai\AiTransportInterface;
use App\Services\Helpdesk\Ai\CurlAiTransport;
use App\Services\Helpdesk\AiSettingsService;
use App\Services\Helpdesk\HelpdeskAdminService;
use App\Services\Helpdesk\HelpdeskAiService;
use App\Services\Helpdesk\HelpdeskSchedulerService;
use App\Services\Helpdesk\KnowledgeBaseService;
use App\Services\Helpdesk\ReporterIdentityService;
use App\Services\Lanpa\LanpaIdentityService;
use App\Services\Helpdesk\SecondLevelReminderService;
use App\Services\Helpdesk\SharedAccountSuggestionService;
use App\Services\Helpdesk\SupportShiftService;
use App\Services\Helpdesk\TaskNumberService;
use App\Services\Helpdesk\TaskService;
use App\Services\Helpdesk\Mail\FileMailboxClient;
use App\Services\Helpdesk\Mail\ImapMailboxClient;
use App\Services\Helpdesk\ErrorOriginSettingsService;
use App\Services\Helpdesk\Mail\MailboxClientFactory;
use App\Services\Helpdesk\Mail\MailboxClientInterface;
use App\Services\Helpdesk\Mail\MimeMessageParser;
use App\Services\Helpdesk\Mail\SmtpTransport;
use App\Services\Helpdesk\MailboxSettingsService;
use App\Services\Helpdesk\NotificationSettingsService;
use App\Services\Helpdesk\OutboundMailSettingsService;
use App\Services\Helpdesk\QuickReportSettingsService;
use App\Services\Helpdesk\TicketConfirmationService;
use App\Services\Helpdesk\TicketMailIngestionService;
use App\Services\Helpdesk\ReplyTemplateSettingsService;
use App\Services\Helpdesk\TicketMailService;
use App\Services\Helpdesk\TicketAttachmentService;
use App\Services\Helpdesk\TicketMergeService;
use App\Services\Helpdesk\TicketNotificationService;
use App\Services\Helpdesk\TicketNumberService;
use App\Services\Helpdesk\TicketReportService;
use App\Services\Helpdesk\TicketRuleEvaluator;
use App\Services\Helpdesk\TicketRuleService;
use App\Services\Helpdesk\TicketService;
use App\Services\Helpdesk\TicketSlaService;
use App\Services\Helpdesk\TicketWorkflowService;
use App\Services\MailClient;
use App\Services\PersonDirectoryService;
use App\Services\SettingsService;
use App\Support\Secret;

return static function (Container $c): void {
    // Repositories
    foreach ([
        TicketRepository::class, TicketMasterDataRepository::class, TicketCategoryRepository::class, TicketSlaRepository::class,
        TicketTagRepository::class, TicketCommentRepository::class, TicketAttachmentRepository::class, TicketWorklogRepository::class,
        TicketRelationRepository::class, TicketTemplateRepository::class, TicketRuleRepository::class, TicketReportRepository::class,
        KnowledgeBaseRepository::class, HelpdeskSupportShiftRepository::class, TaskRepository::class,
    ] as $repository) {
        $c->singleton($repository, static fn (Container $c): object => new $repository($c->get(PDO::class)));
    }

    // Fachlogik ohne Abhängigkeiten
    $c->singleton(TicketWorkflowService::class, static fn (): TicketWorkflowService => new TicketWorkflowService());
    $c->singleton(TicketRuleEvaluator::class, static fn (): TicketRuleEvaluator => new TicketRuleEvaluator());
    $c->singleton(TicketSlaService::class, static function (Container $c): TicketSlaService {
        $config = $c->get(Config::class);

        return new TicketSlaService(
            (string) $config->get('app.timezone', 'Europe/Berlin'),
            (int) $config->get('helpdesk.sla_warning_percent', 75),
            (int) $config->get('helpdesk.sla_escalation_percent', 90)
        );
    });
    $c->singleton(TicketNumberService::class, static fn (Container $c): TicketNumberService => new TicketNumberService($c->get(TicketRepository::class), $c->get(Config::class)));
    $c->singleton(TaskNumberService::class, static fn (Container $c): TaskNumberService => new TaskNumberService($c->get(TaskRepository::class), $c->get(Config::class)));

    $c->singleton(TicketNotificationService::class, static fn (Container $c): TicketNotificationService => new TicketNotificationService(
        $c->get(MailClient::class),
        $c->get(TicketRuleRepository::class),
        $c->get(TicketRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(UserRepository::class),
        $c->get(ReplyTemplateSettingsService::class),
        $c->get(NotificationSettingsService::class),
        $c->get(Config::class),
        $c->get(Logger::class)
    ));
    $c->singleton(TicketMailService::class, static fn (Container $c): TicketMailService => new TicketMailService(
        $c->get(TicketService::class),
        $c->get(TicketNotificationService::class),
        $c->get(MailClient::class),
        $c->get(\App\Services\Lanpa\LanpaBridge::class),
        $c->get(CurrentUser::class),
        $c->get(Config::class),
        $c->get(Logger::class),
        $c->get(ReplyTemplateSettingsService::class)
    ));
    $c->singleton(TicketConfirmationService::class, static fn (Container $c): TicketConfirmationService => new TicketConfirmationService(
        $c->get(ReplyTemplateSettingsService::class),
        $c->get(TicketNotificationService::class),
        $c->get(Config::class)
    ));
    $c->singleton(TicketRuleService::class, static fn (Container $c): TicketRuleService => new TicketRuleService(
        $c->get(TicketRuleRepository::class),
        $c->get(TicketRuleEvaluator::class),
        $c->get(TicketRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketSlaRepository::class),
        $c->get(UserRepository::class),
        $c->get(TicketNotificationService::class),
        $c->get(Logger::class)
    ));
    $c->singleton(TicketService::class, static fn (Container $c): TicketService => new TicketService(
        $c->get(TicketRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketSlaRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketCommentRepository::class),
        $c->get(TicketAttachmentRepository::class),
        $c->get(TicketWorklogRepository::class),
        $c->get(TicketRelationRepository::class),
        $c->get(TicketTemplateRepository::class),
        $c->get(EmployeeRepository::class),
        $c->get(UserRepository::class),
        $c->get(AssetRepository::class),
        $c->get(TicketNumberService::class),
        $c->get(TicketWorkflowService::class),
        $c->get(TicketSlaService::class),
        $c->get(TicketRuleService::class),
        $c->get(TicketNotificationService::class),
        $c->get(TicketConfirmationService::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class),
        $c->get(Config::class),
        $c->get(ErrorOriginSettingsService::class)
    ));
    $c->singleton(TicketMergeService::class, static fn (Container $c): TicketMergeService => new TicketMergeService(
        $c->get(TicketRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketCommentRepository::class),
        $c->get(TicketAttachmentRepository::class),
        $c->get(TicketWorklogRepository::class),
        $c->get(TicketRelationRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketWorkflowService::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class),
        $c->get(TicketMailService::class),
        $c->get(Logger::class)
    ));
    $c->singleton(KnowledgeBaseService::class, static fn (Container $c): KnowledgeBaseService => new KnowledgeBaseService(
        $c->get(KnowledgeBaseRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketRepository::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class)
    ));
    $c->singleton(HelpdeskAdminService::class, static fn (Container $c): HelpdeskAdminService => new HelpdeskAdminService(
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketSlaRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketTemplateRepository::class),
        $c->get(TicketRuleRepository::class),
        $c->get(TicketRuleEvaluator::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class)
    ));
    $c->singleton(TicketReportService::class, static fn (Container $c): TicketReportService => new TicketReportService(
        $c->get(TicketReportRepository::class),
        $c->get(TicketRepository::class),
        $c->get(TicketRelationRepository::class),
        $c->get(CurrentUser::class)
    ));
    $c->singleton(SupportShiftService::class, static fn (Container $c): SupportShiftService => new SupportShiftService(
        $c->get(HelpdeskSupportShiftRepository::class),
        $c->get(TicketRepository::class),
        $c->get(TicketService::class),
        $c->get(CurrentUser::class),
        $c->get(AuditLogService::class),
        $c->get(Config::class)
    ));
    $c->singleton(SecondLevelReminderService::class, static fn (Container $c): SecondLevelReminderService => new SecondLevelReminderService(
        $c->get(HelpdeskSupportShiftRepository::class),
        $c->get(TicketRepository::class),
        $c->get(TicketCommentRepository::class),
        $c->get(Config::class),
        $c->get(Logger::class)
    ));
    $c->singleton(HelpdeskSchedulerService::class, static fn (Container $c): HelpdeskSchedulerService => new HelpdeskSchedulerService(
        $c->get(TicketRepository::class),
        $c->get(TicketSlaRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketSlaService::class),
        $c->get(TicketNotificationService::class),
        $c->get(TicketRuleService::class),
        $c->get(SupportShiftService::class),
        $c->get(SecondLevelReminderService::class),
        $c->get(Config::class),
        $c->get(Logger::class)
    ));
    $c->singleton(MimeMessageParser::class, static fn (): MimeMessageParser => new MimeMessageParser());
    $c->singleton(Secret::class, static function (Container $c): Secret {
        $config = $c->get(Config::class);

        return Secret::fromEnvironment(
            (string) $config->get('app.key', ''),
            (string) $config->get('database.password', '') . '|' . (string) $config->get('app.url', '')
        );
    });
    $c->singleton(MailboxSettingsService::class, static fn (Container $c): MailboxSettingsService => new MailboxSettingsService(
        $c->get(SettingsService::class),
        $c->get(Config::class),
        $c->get(Secret::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class),
        $c->get(UserRepository::class),
        $c->get(Permissions::class),
        $c->get(TicketMasterDataRepository::class)
    ));
    // Ausgehende E-Mails: Versandkonto samt Servereinstellungen aus der Administration
    $c->singleton(SmtpTransport::class, static fn (Container $c): SmtpTransport => new SmtpTransport($c->get(Config::class)));
    $c->singleton(OutboundMailSettingsService::class, static fn (Container $c): OutboundMailSettingsService => new OutboundMailSettingsService(
        $c->get(SettingsService::class),
        $c->get(Config::class),
        $c->get(Secret::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class)
    ));
    // KI-Unterstützung: OpenAI-kompatibler Endpunkt (Adresse, Modell und Schlüssel aus der Administration)
    $c->singleton(AiTransportInterface::class, static fn (): AiTransportInterface => new CurlAiTransport());
    $c->singleton(AiSettingsService::class, static fn (Container $c): AiSettingsService => new AiSettingsService(
        $c->get(SettingsService::class),
        $c->get(Config::class),
        $c->get(Secret::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class),
        $c->get(AiTransportInterface::class),
        $c->get(\App\Services\Lanpa\LanpaBridge::class)
    ));
    $c->singleton(HelpdeskAiService::class, static fn (Container $c): HelpdeskAiService => new HelpdeskAiService(
        $c->get(AiSettingsService::class),
        $c->get(AiTransportInterface::class),
        dirname(__DIR__, 3) . '/storage/tmp/helpdesk-ai-availability.json'
    ));

    // Postfach-Client je Konfiguration aus der Administration (imap = Socket-IMAP-Client, file = .eml-Verzeichnis)
    $c->singleton(MailboxClientInterface::class, static function (Container $c): MailboxClientInterface {
        return MailboxClientFactory::create($c->get(MailboxSettingsService::class)->mailboxOptions(), dirname(__DIR__, 3));
    });
    $c->singleton(TicketMailIngestionService::class, static fn (Container $c): TicketMailIngestionService => new TicketMailIngestionService(
        $c->get(Config::class),
        $c->get(Logger::class),
        $c->get(CurrentUser::class),
        $c->get(Permissions::class),
        $c->get(TicketRepository::class),
        $c->get(TicketRuleRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketAttachmentRepository::class),
        $c->get(EmployeeRepository::class),
        $c->get(UserRepository::class),
        $c->get(TicketService::class),
        $c->get(DocumentService::class),
        $c->get(MimeMessageParser::class),
        $c->get(MailboxSettingsService::class),
        static fn (): MailboxClientInterface => MailboxClientFactory::create($c->get(MailboxSettingsService::class)->mailboxOptions(), dirname(__DIR__, 3))
    ));

    // Controller
    $c->singleton(HelpdeskDashboardController::class, static fn (Container $c): HelpdeskDashboardController => new HelpdeskDashboardController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(TicketReportService::class),
        $c->get(TicketRepository::class),
        $c->get(SupportShiftService::class)
    ));
    $c->singleton(TicketAttachmentService::class, static fn (Container $c): TicketAttachmentService => new TicketAttachmentService(
        $c->get(TicketService::class),
        $c->get(DocumentService::class),
        $c->get(TicketAttachmentRepository::class),
        $c->get(TicketRepository::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class)
    ));
    $c->singleton(TicketController::class, static fn (Container $c): TicketController => new TicketController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(TicketService::class),
        $c->get(TicketMergeService::class),
        $c->get(TicketReportService::class),
        $c->get(KnowledgeBaseService::class),
        $c->get(TicketRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketSlaRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketTemplateRepository::class),
        $c->get(TicketWorklogRepository::class),
        $c->get(TicketRelationRepository::class),
        $c->get(TicketRuleRepository::class),
        $c->get(TicketSlaService::class),
        $c->get(EmployeeRepository::class),
        $c->get(AssetRepository::class),
        $c->get(LocationRepository::class),
        $c->get(CostCenterRepository::class),
        $c->get(Config::class),
        $c->get(SupportShiftService::class),
        $c->get(HelpdeskAiService::class),
        $c->get(TicketAttachmentService::class),
        $c->get(TicketMailService::class),
        $c->get(UserRepository::class),
        $c->get(ReplyTemplateSettingsService::class),
        $c->get(ErrorOriginSettingsService::class),
        $c->get(TaskRepository::class)
    ));
    $c->singleton(TaskService::class, static fn (Container $c): TaskService => new TaskService(
        $c->get(TaskRepository::class),
        $c->get(TaskNumberService::class),
        $c->get(TicketService::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class)
    ));
    $c->singleton(TaskController::class, static fn (Container $c): TaskController => new TaskController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(TaskService::class),
        $c->get(TaskRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(Config::class)
    ));
    $c->singleton(TicketAttachmentController::class, static fn (Container $c): TicketAttachmentController => new TicketAttachmentController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(TicketService::class),
        $c->get(TicketAttachmentRepository::class),
        $c->get(TicketRepository::class),
        $c->get(DocumentService::class),
        $c->get(AuditLogService::class),
        $c->get(TicketAttachmentService::class)
    ));
    $c->singleton(PortalController::class, static fn (Container $c): PortalController => new PortalController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(TicketService::class),
        $c->get(KnowledgeBaseService::class),
        $c->get(TicketRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketTemplateRepository::class),
        $c->get(KnowledgeBaseRepository::class),
        $c->get(TicketSlaService::class),
        $c->get(Config::class)
    ));
    $c->singleton(ReporterIdentityService::class, static fn (Container $c): ReporterIdentityService => new ReporterIdentityService(
        $c->get(Config::class),
        $c->get(CurrentUser::class),
        $c->get(UserRepository::class),
        $c->get(EmployeeRepository::class),
        $c->get(AdUserLookupService::class),
        $c->get(LanpaIdentityService::class),
        $c->get(\App\Services\Lanpa\LanpaBridge::class)
    ));
    $c->singleton(QuickReportSettingsService::class, static fn (Container $c): QuickReportSettingsService => new QuickReportSettingsService(
        $c->get(SettingsService::class),
        $c->get(Config::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class)
    ));
    $c->singleton(ErrorOriginSettingsService::class, static fn (Container $c): ErrorOriginSettingsService => new ErrorOriginSettingsService(
        $c->get(SettingsService::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class)
    ));
    $c->singleton(ReplyTemplateSettingsService::class, static fn (Container $c): ReplyTemplateSettingsService => new ReplyTemplateSettingsService(
        $c->get(SettingsService::class),
        $c->get(Config::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class)
    ));
    $c->singleton(NotificationSettingsService::class, static fn (Container $c): NotificationSettingsService => new NotificationSettingsService(
        $c->get(SettingsService::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class)
    ));
    $c->singleton(QuickReportController::class, static fn (Container $c): QuickReportController => new QuickReportController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(TicketService::class),
        $c->get(ReporterIdentityService::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(PersonDirectoryService::class),
        $c->get(EmployeeRepository::class),
        $c->get(UserRepository::class),
        $c->get(Permissions::class),
        $c->get(Config::class),
        $c->get(Logger::class),
        $c->get(QuickReportSettingsService::class)
    ));
    $c->singleton(KnowledgeBaseController::class, static fn (Container $c): KnowledgeBaseController => new KnowledgeBaseController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(KnowledgeBaseService::class),
        $c->get(KnowledgeBaseRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketService::class),
        $c->get(Config::class)
    ));
    $c->singleton(HelpdeskReportController::class, static fn (Container $c): HelpdeskReportController => new HelpdeskReportController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(TicketReportService::class)
    ));
    // Vorschlaege fuer das Feld „Sammelkonten“ (Telefonbuch, Mitarbeiter, lokale Konten)
    $c->singleton(SharedAccountSuggestionService::class, static fn (Container $c): SharedAccountSuggestionService => new SharedAccountSuggestionService(
        $c->get(\App\Services\Lanpa\LanpaBridge::class),
        $c->get(EmployeeRepository::class),
        $c->get(UserRepository::class)
    ));
    $c->singleton(HelpdeskAdminController::class, static fn (Container $c): HelpdeskAdminController => new HelpdeskAdminController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(HelpdeskAdminService::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketSlaRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketTemplateRepository::class),
        $c->get(TicketRuleRepository::class),
        $c->get(TicketRuleEvaluator::class),
        $c->get(TicketNotificationService::class),
        $c->get(TicketMailIngestionService::class),
        $c->get(MailboxSettingsService::class),
        $c->get(QuickReportSettingsService::class),
        $c->get(ErrorOriginSettingsService::class),
        $c->get(ReplyTemplateSettingsService::class),
        $c->get(NotificationSettingsService::class),
        $c->get(SharedAccountSuggestionService::class),
        $c->get(Config::class)
    ));
    $c->singleton(HelpdeskMailboxController::class, static fn (Container $c): HelpdeskMailboxController => new HelpdeskMailboxController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(MailboxSettingsService::class),
        $c->get(TicketMailIngestionService::class),
        $c->get(TicketRuleRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(Config::class),
        dirname(__DIR__, 3)
    ));
    $c->singleton(HelpdeskOutboundMailController::class, static fn (Container $c): HelpdeskOutboundMailController => new HelpdeskOutboundMailController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(OutboundMailSettingsService::class),
        $c->get(SmtpTransport::class),
        $c->get(MailClient::class),
        $c->get(Config::class)
    ));
    $c->singleton(HelpdeskAiController::class, static fn (Container $c): HelpdeskAiController => new HelpdeskAiController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(AiSettingsService::class),
        $c->get(HelpdeskAiService::class)
    ));
    $c->singleton(HelpdeskApiController::class, static fn (Container $c): HelpdeskApiController => new HelpdeskApiController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(TicketService::class),
        $c->get(TicketRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketTemplateRepository::class),
        $c->get(KnowledgeBaseService::class),
        $c->get(KnowledgeBaseRepository::class),
        $c->get(HelpdeskAiService::class),
        $c->get(PersonDirectoryService::class),
        $c->get(Config::class)
    ));
    $c->singleton(HelpdeskSupportShiftController::class, static fn (Container $c): HelpdeskSupportShiftController => new HelpdeskSupportShiftController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(SupportShiftService::class)
    ));
};
