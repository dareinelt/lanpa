<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Repositories\ArticleRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\ProcurementRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\SupplierRepository;
use App\Security\CurrentUser;
use App\Services\PurchaseOrderService;

final class ProcurementController extends BaseController
{
    public function __construct(
        View $view, CurrentUser $currentUser, private readonly ProcurementRepository $procurement,
        private readonly PurchaseOrderRepository $orders, private readonly PurchaseOrderService $orderService,
        private readonly ArticleRepository $articles, private readonly SupplierRepository $suppliers,
        private readonly CostCenterRepository $costCenters
    ) { parent::__construct($view, $currentUser); }

    public function templates(Request $request): Response
    {
        return $this->render('orders.templates', ['title' => 'Bestellvorlagen', 'activeNav' => 'orders', 'templates' => $this->procurement->templates()]);
    }

    public function saveTemplate(Request $request): Response
    {
        $this->currentUser->require('orders.manage');
        $order = $this->findOrFail($this->orders->find($request->paramInt('id')), 'Bestellung nicht gefunden');
        $name = trim($request->string('name'));
        if ($name === '') { $this->flash('error', 'Bitte einen Namen für die Vorlage angeben.'); return $this->redirect('/orders/' . $order['id']); }
        $this->procurement->transaction(function () use ($order, $name): void {
            $id = $this->procurement->createTemplate(['name' => $name, 'supplier_id' => $order['supplier_id'], 'cost_center_id' => $order['cost_center_id'], 'note' => $order['note'], 'created_by' => $this->currentUser->id()]);
            foreach ($this->orders->items((int) $order['id']) as $item) {
                $this->procurement->createTemplateItem(['purchase_order_template_id' => $id, 'position' => $item['position'], 'article_id' => $item['article_id'], 'asset_type_id' => $item['asset_type_id'], 'description' => $item['description'], 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'], 'creates_assets' => $item['creates_assets'], 'note' => $item['note']]);
            }
        });
        $this->flash('success', 'Bestellvorlage gespeichert.');
        return $this->redirect('/orders/templates');
    }

    public function useTemplate(Request $request): Response
    {
        $this->currentUser->require('orders.manage');
        $template = $this->findOrFail($this->procurement->template($request->paramInt('id')), 'Vorlage nicht gefunden');
        if ($template['supplier_id'] === null) { $this->flash('error', 'Die Vorlage hat keinen Lieferanten.'); return $this->redirect('/orders/templates'); }
        $orderId = $this->orderService->create(['supplier_id' => (string) $template['supplier_id'], 'cost_center_id' => (string) ($template['cost_center_id'] ?? ''), 'note' => (string) ($template['note'] ?? '')]);
        foreach ($this->procurement->templateItems((int) $template['id']) as $item) {
            $this->orderService->addItem($orderId, $this->orders->find($orderId) ?? [], $item);
        }
        $this->flash('success', 'Entwurf aus Vorlage erstellt.');
        return $this->redirect('/orders/' . $orderId);
    }

    public function requests(Request $request): Response
    {
        $all = $this->currentUser->can('orders.manage');
        return $this->render('orders.requests', ['title' => 'Bedarfsmeldungen', 'activeNav' => 'orders', 'requests' => $this->procurement->requests($all ? null : $this->currentUser->id()), 'articles' => $this->articles->activeForSelect(), 'costCenters' => $this->costCenters->activeForSelect(), 'suppliers' => $this->suppliers->activeForSelect()]);
    }

    public function storeRequest(Request $request): Response
    {
        $description = trim($request->string('description'));
        $quantity = $request->int('quantity') ?? 0;
        $articleId = $request->int('article_id');
        if ($articleId !== null) {
            $article = $this->articles->find($articleId);
            if ($article === null || (int) $article['is_active'] !== 1) {
                $this->flash('error', 'Der ausgewählte Artikel ist nicht verfügbar.');
                return $this->redirect('/orders/requests');
            }
            $description = $article['manufacturer_name'] . ' ' . $article['name'];
        }
        if ($description === '' || $quantity < 1) { $this->flash('error', 'Bitte Artikel/Bezeichnung und Menge angeben.'); return $this->redirect('/orders/requests'); }
        $id = $this->procurement->transaction(function () use ($request, $description, $quantity, $articleId): int {
            $id = $this->procurement->createRequest(['requested_by' => $this->currentUser->id(), 'requested_by_name' => $this->currentUser->displayName(), 'cost_center_id' => $request->int('cost_center_id'), 'note' => $request->stringOrNull('note')]);
            $this->procurement->createRequestItem(['purchase_request_id' => $id, 'article_id' => $articleId, 'description' => $description, 'quantity' => $quantity, 'note' => null]);
            return $id;
        });
        $this->flash('success', 'Bedarfsmeldung #' . $id . ' angelegt.');
        return $this->redirect('/orders/requests');
    }

    public function convertRequest(Request $request): Response
    {
        $this->currentUser->require('orders.manage');
        $demandId = $request->paramInt('id');
        $supplierId = $request->int('supplier_id') ?? 0;
        $orderId = $this->procurement->transaction(function () use ($demandId, $supplierId): int {
            if (!$this->procurement->claimRequest($demandId)) {
                throw new ConflictException('Die Bedarfsmeldung wurde bereits verarbeitet.');
            }
            $demand = $this->findOrFail($this->procurement->request($demandId), 'Bedarfsmeldung nicht gefunden');
            $orderId = $this->orderService->create(['supplier_id' => (string) $supplierId, 'cost_center_id' => (string) ($demand['cost_center_id'] ?? ''), 'note' => 'Aus Bedarfsmeldung #' . $demand['id'] . ($demand['note'] ? ': ' . $demand['note'] : '')]);
            foreach ($this->procurement->requestItems((int) $demand['id']) as $item) {
                $this->orderService->addItem($orderId, $this->orders->find($orderId) ?? [], ['article_id' => (string) ($item['article_id'] ?? ''), 'description' => $item['description'], 'quantity' => (string) $item['quantity'], 'creates_assets' => '0', 'note' => $item['note']]);
            }
            $this->procurement->updateRequest((int) $demand['id'], ['purchase_order_id' => $orderId]);
            return $orderId;
        });
        $this->flash('success', 'Bedarfsmeldung in Bestellung übernommen.');
        return $this->redirect('/orders/' . $orderId);
    }

    public function replenishment(Request $request): Response
    {
        return $this->render('orders.replenishment', ['title' => 'Bestellvorschläge', 'activeNav' => 'orders', 'suggestions' => $this->articles->replenishmentSuggestions(), 'suppliers' => $this->suppliers->activeForSelect()]);
    }

    public function createReplenishment(Request $request): Response
    {
        $this->currentUser->require('orders.manage');
        $supplierId = $request->int('supplier_id') ?? 0;
        $suggestions = $this->articles->replenishmentSuggestions();
        if ($supplierId < 1 || $suggestions === []) { $this->flash('error', 'Bitte Lieferant wählen; es müssen Bestellvorschläge vorhanden sein.'); return $this->redirect('/orders/replenishment'); }
        $orderId = $this->orderService->create(['supplier_id' => (string) $supplierId, 'note' => 'Automatisch aus Bestandsunterschreitungen erstellt.']);
        foreach ($suggestions as $article) {
            $this->orderService->addItem($orderId, $this->orders->find($orderId) ?? [], ['article_id' => (string) $article['id'], 'quantity' => (string) max(1, (int) $article['minimum_stock'] * 2 - (int) $article['stock_quantity']), 'creates_assets' => '0']);
        }
        $this->flash('success', 'Bestellvorschlag als Entwurf erstellt.');
        return $this->redirect('/orders/' . $orderId);
    }
}
