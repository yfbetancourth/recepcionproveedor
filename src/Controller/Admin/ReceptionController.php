<?php
declare(strict_types=1);

namespace PrestaShop\Module\RecepcionProveedor\Controller\Admin;

use PrestaShop\Module\RecepcionProveedor\Exception\ReceptionException;
use PrestaShop\Module\RecepcionProveedor\Form\ReceptionHeaderType;
use PrestaShop\Module\RecepcionProveedor\Repository\ReceptionRepository;
use PrestaShop\Module\RecepcionProveedor\Service\ReceptionConfirmer;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Permisos (Configuración avanzada > Equipo > Permisos > "Recepciones de proveedor"):
 *  - ver:      listado y detalle
 *  - añadir:   crear borradores
 *  - editar:   modificar borradores y confirmar
 *  - eliminar: borrar borradores
 */
class ReceptionController extends PrestaShopAdminController
{
    private const DOMAIN = 'Modules.Recepcionproveedor.Admin';
    private const CSRF_ID = 'recepcionproveedor';
    private const TEMPLATE_DIR = '@Modules/recepcionproveedor/views/templates/admin/';

    public function __construct(
        private readonly ReceptionRepository $repository,
        private readonly ReceptionConfirmer $confirmer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function indexAction(Request $request): Response
    {
        $status = $request->query->get('status');
        if (!in_array($status, ['draft', 'confirmed'], true)) {
            $status = null;
        }

        return $this->render(self::TEMPLATE_DIR . 'index.html.twig', [
            'layoutTitle' => $this->t('Recepciones de proveedor'),
            'enableSidebar' => true,
            'receptions' => $this->decoratePaymentLabels($this->repository->findAll($this->shopId(), $status, 100, (int) \Context::getContext()->language->id)),
            'summary' => $this->repository->getSummary($this->shopId()),
            'currentStatus' => $status,
        ]);
    }

    /** Paso 1: crea la recepción en borrador y abre su edición. */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))")]
    public function createAction(Request $request): RedirectResponse
    {
        if (!$this->validCsrf($request)) {
            return $this->invalidCsrf();
        }

        $id = $this->repository->createDraft($this->employeeId(), $this->shopId(), (int) \Configuration::get('PS_OS_PREPARATION'));
        $this->addFlash('success', $this->t('Recepción en borrador creada.'));

        return $this->redirectToRoute('recepcionproveedor_edit', ['id' => $id]);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function editAction(int $id): Response
    {
        $reception = $this->repository->find($id, $this->shopId());
        if (null === $reception) {
            throw $this->createNotFoundException();
        }

        return $this->renderEdit($reception, $this->buildHeaderForm($reception));
    }

    /** Pasos 2 y 3: proveedor, número y fecha del recibo. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function saveHeaderAction(Request $request, int $id): Response
    {
        $reception = $this->repository->find($id, $this->shopId());
        if (null === $reception) {
            throw $this->createNotFoundException();
        }
        if ('draft' !== $reception['status']) {
            return $this->notDraft($id);
        }

        $form = $this->buildHeaderForm($reception);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $idSupplier = (int) ($data['id_supplier'] ?? 0);
            $supplierSearch = trim((string) $form->get('supplier_search')->getData());
            if ((('' !== $supplierSearch) && $idSupplier < 1) || ($idSupplier > 0 && ('' === $supplierSearch || !$this->repository->isActiveSupplierForShop($idSupplier, $this->shopId())))) {
                $form->get('supplier_search')->addError(new FormError($this->t('Selecciona un proveedor de la lista de resultados.')));

                return $this->renderEdit($reception, $form);
            }

            $number = trim((string) ($data['receipt_number'] ?? ''));
            $notes = trim((string) ($data['notes'] ?? ''));
            $date = $data['receipt_date'] ?? null;

            $this->repository->updateHeader(
                $id,
                $idSupplier > 0 ? $idSupplier : null,
                '' !== $number ? $number : null,
                $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : null,
                '' !== $notes ? $notes : null,
                $data['payment_method'] ?: null,
                (int) $data['id_order_state'],
            );
            $this->addFlash('success', $this->t('Cabecera guardada.'));

            return $this->redirectToRoute('recepcionproveedor_edit', ['id' => $id]);
        }

        return $this->renderEdit($reception, $form);
    }

    /** Paso 4a: añadir un producto con cantidad y costo. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function addLineAction(Request $request, int $id): RedirectResponse
    {
        if (!$this->validCsrf($request)) {
            return $this->invalidCsrf();
        }
        $reception = $this->repository->find($id, $this->shopId());
        if (null === $reception) {
            throw $this->createNotFoundException();
        }
        if ('draft' !== $reception['status']) {
            return $this->notDraft($id);
        }

        $idProduct = (int) $request->request->get('id_product');
        $idPa = (int) $request->request->get('id_product_attribute');
        $quantity = $this->parseQuantity($request->request->get('quantity'));
        $cost = $this->parseCost($request->request->get('unit_cost'));

        if ($idProduct < 1) {
            $this->addFlash('error', $this->t('Selecciona un producto de la lista de resultados.'));
        } elseif (null === $quantity) {
            $this->addFlash('error', $this->t('La cantidad debe ser un número entero mayor que cero.'));
        } elseif (null === $cost) {
            $this->addFlash('error', $this->t('El costo debe ser un número igual o mayor que cero.'));
        } else {
            try {
                $this->repository->addLine($id, $idProduct, $idPa, $quantity, $cost);
                $this->addFlash('success', $this->t('Producto añadido.'));
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', match ($e->getMessage()) {
                    'combination_required' => $this->t('Este producto tiene combinaciones: elige una combinación concreta.'),
                    'combination_not_found' => $this->t('La combinación no pertenece al producto.'),
                    default => $this->t('El producto no existe.'),
                });
            }
        }

        return $this->redirectToRoute('recepcionproveedor_edit', ['id' => $id]);
    }

    /** Paso 4b: guardar cantidades y costos editados en la tabla. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function saveLinesAction(Request $request, int $id): RedirectResponse
    {
        if (!$this->validCsrf($request)) {
            return $this->invalidCsrf();
        }
        $reception = $this->repository->find($id, $this->shopId());
        if (null === $reception) {
            throw $this->createNotFoundException();
        }
        if ('draft' !== $reception['status']) {
            return $this->notDraft($id);
        }

        $errors = 0;
        foreach ($request->request->all('lines') as $lineId => $values) {
            $quantity = $this->parseQuantity($values['quantity'] ?? null);
            $cost = $this->parseCost($values['unit_cost'] ?? null);
            if (null === $quantity || null === $cost) {
                ++$errors;
                continue;
            }
            $this->repository->updateLine($id, (int) $lineId, $quantity, $cost);
        }

        if ($errors > 0) {
            $this->addFlash('error', $this->t('Hay líneas con cantidad o costo no válidos; esas líneas no se han modificado.'));
        } else {
            $this->addFlash('success', $this->t('Líneas guardadas.'));
        }

        return $this->redirectToRoute('recepcionproveedor_edit', ['id' => $id]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function deleteLineAction(Request $request, int $id, int $lineId): RedirectResponse
    {
        if (!$this->validCsrf($request)) {
            return $this->invalidCsrf();
        }
        $reception = $this->repository->find($id, $this->shopId());
        if (null === $reception) {
            throw $this->createNotFoundException();
        }
        if ('draft' !== $reception['status']) {
            return $this->notDraft($id);
        }

        $this->repository->deleteLine($id, $lineId);
        $this->addFlash('success', $this->t('Línea eliminada.'));

        return $this->redirectToRoute('recepcionproveedor_edit', ['id' => $id]);
    }

    /** Pasos 5 y 6: confirmar la recepción y actualizar el stock. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function confirmAction(Request $request, int $id): Response
    {
        $reception = $this->repository->find($id, $this->shopId());
        if (null === $reception) {
            throw $this->createNotFoundException();
        }
        if ('draft' !== $reception['status']) {
            return $this->notDraft($id);
        }

        // Confirmar envía la cabecera actual junto con el proveedor seleccionado,
        // para no depender de que se haya pulsado antes "Guardar cabecera".
        $form = $this->buildHeaderForm($reception);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderEdit($reception, $form);
        }

        $data = $form->getData();
        $idSupplier = (int) ($data['id_supplier'] ?? 0);
        $supplierSearch = trim((string) $form->get('supplier_search')->getData());
        if ($idSupplier < 1 || '' === $supplierSearch) {
            $form->get('supplier_search')->addError(new FormError($this->t('Selecciona un proveedor existente antes de confirmar.')));

            return $this->renderEdit($reception, $form);
        }
        if (!$this->repository->isActiveSupplierForShop($idSupplier, $this->shopId())) {
            $form->get('supplier_search')->addError(new FormError($this->t('El proveedor seleccionado ya no está activo en esta tienda.')));

            return $this->renderEdit($reception, $form);
        }

        $number = trim((string) ($data['receipt_number'] ?? ''));
        if ('' === $number) {
            $form->get('receipt_number')->addError(new FormError($this->t('Introduce el numero de recibo antes de confirmar.')));

            return $this->renderEdit($reception, $form);
        }
        $date = $data['receipt_date'] ?? null;
        if (!$date instanceof \DateTimeInterface) {
            $form->get('receipt_date')->addError(new FormError($this->t('Introduce la fecha del recibo antes de confirmar.')));

            return $this->renderEdit($reception, $form);
        }

        $notes = trim((string) ($data['notes'] ?? ''));
        $this->repository->updateHeader(
            $id,
            $idSupplier,
            $number,
            $date->format('Y-m-d'),
            '' !== $notes ? $notes : null,
            $data['payment_method'] ?: null,
            (int) $data['id_order_state']
        );

        try {
            $lines = $this->confirmer->confirm($id, $this->employeeId(), $this->shopId());
            $this->addFlash('success', $this->t(
                'Recepción confirmada. Stock actualizado en %count% línea(s).',
                ['%count%' => $lines]
            ));
        } catch (ReceptionException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->addFlash('error', $this->t('No se pudo confirmar la recepción. No se ha modificado el stock.'));
        }

        return $this->redirectToRoute('recepcionproveedor_edit', ['id' => $id]);
    }

    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))")]
    public function deleteAction(Request $request, int $id): RedirectResponse
    {
        if (!$this->validCsrf($request)) {
            return $this->invalidCsrf();
        }
        $reception = $this->repository->find($id, $this->shopId());
        if (null === $reception) {
            throw $this->createNotFoundException();
        }

        if ($this->repository->deleteDraft($id)) {
            $this->addFlash('success', $this->t('Borrador eliminado.'));

            return $this->redirectToRoute('recepcionproveedor_index');
        }

        return $this->notDraft($id);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function searchProductsAction(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        if (mb_strlen($query) < 2) {
            return new JsonResponse([]);
        }

        return new JsonResponse($this->repository->searchProducts(
            $query,
            (int) \Context::getContext()->language->id,
            $this->shopId()
        ));
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function searchSuppliersAction(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        if (mb_strlen($query) < 2) {
            return new JsonResponse([]);
        }

        return new JsonResponse(array_map(fn (array $supplier): array => [
            'id_supplier' => (int) $supplier['id_supplier'],
            'name' => (string) $supplier['name'],
            'details_url' => $this->generateUrl('admin_suppliers_edit', [
                'supplierId' => (int) $supplier['id_supplier'],
            ]),
        ], $this->repository->searchSuppliers($query, $this->shopId())));
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string, mixed> $reception */
    private function buildHeaderForm(array $reception): FormInterface
    {
        $form = $this->createForm(ReceptionHeaderType::class, [
            'id_supplier' => null !== $reception['id_supplier'] ? (int) $reception['id_supplier'] : null,
            'receipt_number' => $reception['receipt_number'],
            'receipt_date' => $reception['receipt_date'] ? new \DateTimeImmutable($reception['receipt_date']) : null,
            'notes' => $reception['notes'],
            'payment_method' => $reception['payment_method'] ?? null,
            'id_order_state' => (int) ($reception['id_order_state'] ?? \Configuration::get('PS_OS_PREPARATION')),
        ], [
            'payment_module_choices' => $this->paymentModuleChoices($reception['payment_method'] ?? null),
            'order_state_choices' => $this->orderStateChoices(),
            'action' => $this->generateUrl('recepcionproveedor_save_header', ['id' => (int) $reception['id_reception']]),
            'disabled' => 'draft' !== $reception['status'],
        ]);
        $form->get('supplier_search')->setData((string) ($reception['supplier_name'] ?? ''));

        return $form;
    }

    /** @return array<string, string> Display label => installed module technical name. */
    private function paymentModuleChoices(?string $currentValue = null): array
    {
        $choices = [];
        foreach (\PaymentModule::getInstalledPaymentModules() ?: [] as $payment) {
            $module = \Module::getInstanceByName((string) $payment['name']);
            if (\Validate::isLoadedObject($module) && $module->active) {
                $choices[(string) $module->displayName] = (string) $module->name;
            }
        }
        if ($currentValue && !in_array($currentValue, $choices, true)) {
            $choices[$currentValue] = $currentValue;
        }

        return $choices;
    }

    /** @return array<string, int> Order state label => state ID. */
    private function orderStateChoices(): array
    {
        $choices = [];
        foreach (\OrderState::getOrderStates((int) \Context::getContext()->language->id) ?: [] as $state) {
            $choices[(string) $state['name']] = (int) $state['id_order_state'];
        }

        return $choices;
    }

    /** @param array<int, array<string, mixed>> $receptions
     *  @return array<int, array<string, mixed>>
     */
    private function decoratePaymentLabels(array $receptions): array
    {
        $labels = array_flip($this->paymentModuleChoices());
        foreach ($receptions as &$reception) {
            $method = $reception['payment_method'] ?? null;
            $reception['payment_label'] = $method ? ($labels[$method] ?? $method) : null;
        }
        unset($reception);

        return $receptions;
    }

    /** @param array<string, mixed> $reception */
    private function renderEdit(array $reception, FormInterface $form): Response
    {
        $lines = $this->repository->getLines((int) $reception['id_reception'], (int) \Context::getContext()->language->id);
        $reception['payment_label'] = $this->decoratePaymentLabels([$reception])[0]['payment_label'];

        $totalUnits = 0;
        $totalCost = 0.0;
        foreach ($lines as $line) {
            $totalUnits += $line['quantity'];
            $totalCost += $line['quantity'] * $line['unit_cost'];
        }

        return $this->render(self::TEMPLATE_DIR . 'edit.html.twig', [
            'layoutTitle' => $this->t('Recepción #%id%', ['%id%' => $reception['id_reception']]),
            'enableSidebar' => true,
            'reception' => $reception,
            'lines' => $lines,
            'form' => $form->createView(),
            'totalUnits' => $totalUnits,
            'totalCost' => $totalCost,
        ]);
    }

    private function notDraft(int $id): RedirectResponse
    {
        $this->addFlash('error', $this->t('Solo se pueden modificar recepciones en borrador.'));

        return $this->redirectToRoute('recepcionproveedor_edit', ['id' => $id]);
    }

    private function validCsrf(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_csrf'));
    }

    private function invalidCsrf(): RedirectResponse
    {
        $this->addFlash('error', $this->t('Token de seguridad no válido. Vuelve a intentarlo.'));

        return $this->redirectToRoute('recepcionproveedor_index');
    }

    private function parseQuantity(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);

        return false === $int ? null : $int;
    }

    private function parseCost(mixed $value): ?float
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ('' === $value || !is_numeric($value)) {
            return null;
        }
        $cost = round((float) $value, 6);

        return ($cost < 0 || $cost > 9999999999) ? null : $cost;
    }

    private function t(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, self::DOMAIN);
    }

    private function shopId(): int
    {
        return (int) \Context::getContext()->shop->id;
    }

    private function employeeId(): int
    {
        return (int) \Context::getContext()->employee->id;
    }
}
