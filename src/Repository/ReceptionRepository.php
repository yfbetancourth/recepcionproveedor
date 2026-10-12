<?php
declare(strict_types=1);

namespace PrestaShop\Module\RecepcionProveedor\Repository;

use Doctrine\DBAL\Connection;

class ReceptionRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $dbPrefix,
    ) {
    }

    private function t(string $table): string
    {
        return '`' . $this->dbPrefix . $table . '`';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(int $idShop, ?string $status = null, int $limit = 100): array
    {
        $params = ['shop' => $idShop];
        $where = 'r.id_shop = :shop';
        if (null !== $status) {
            $where .= ' AND r.status = :status';
            $params['status'] = $status;
        }

        $sql = 'SELECT r.id_reception, r.receipt_number, r.receipt_date, r.status, r.date_add, r.date_confirmed,
                       s.name AS supplier_name,
                       COUNT(l.id_line) AS total_lines,
                       COALESCE(SUM(l.quantity), 0) AS total_units,
                       COALESCE(SUM(l.quantity * l.unit_cost), 0) AS total_cost
                FROM ' . $this->t('recepcionproveedor') . ' r
                LEFT JOIN ' . $this->t('supplier') . ' s ON s.id_supplier = r.id_supplier
                LEFT JOIN ' . $this->t('recepcionproveedor_line') . ' l ON l.id_reception = r.id_reception
                WHERE ' . $where . '
                GROUP BY r.id_reception, s.name
                ORDER BY r.id_reception DESC
                LIMIT ' . (int) $limit;

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * @return array{total: int, drafts: int, confirmed: int, confirmed_units: int, confirmed_cost: float}
     */
    public function getSummary(int $idShop): array
    {
        $sql = "SELECT COUNT(*) AS total,
                       COALESCE(SUM(CASE WHEN r.status = 'draft' THEN 1 ELSE 0 END), 0) AS drafts,
                       COALESCE(SUM(CASE WHEN r.status = 'confirmed' THEN 1 ELSE 0 END), 0) AS confirmed,
                       COALESCE(SUM(CASE WHEN r.status = 'confirmed' THEN COALESCE(line_totals.total_units, 0) ELSE 0 END), 0) AS confirmed_units,
                       COALESCE(SUM(CASE WHEN r.status = 'confirmed' THEN COALESCE(line_totals.total_cost, 0) ELSE 0 END), 0) AS confirmed_cost
                FROM " . $this->t('recepcionproveedor') . " r
                LEFT JOIN (
                    SELECT id_reception, SUM(quantity) AS total_units, SUM(quantity * unit_cost) AS total_cost
                    FROM " . $this->t('recepcionproveedor_line') . "
                    GROUP BY id_reception
                ) line_totals ON line_totals.id_reception = r.id_reception
                WHERE r.id_shop = :shop";
        $row = $this->connection->fetchAssociative($sql, ['shop' => $idShop]);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'drafts' => (int) ($row['drafts'] ?? 0),
            'confirmed' => (int) ($row['confirmed'] ?? 0),
            'confirmed_units' => (int) ($row['confirmed_units'] ?? 0),
            'confirmed_cost' => (float) ($row['confirmed_cost'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id, int $idShop): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT r.*, s.name AS supplier_name
             FROM ' . $this->t('recepcionproveedor') . ' r
             LEFT JOIN ' . $this->t('supplier') . ' s ON s.id_supplier = r.id_supplier
             WHERE r.id_reception = :id AND r.id_shop = :shop',
            ['id' => $id, 'shop' => $idShop]
        );

        return false === $row ? null : $row;
    }

    public function createDraft(int $idEmployee, int $idShop): int
    {
        $now = date('Y-m-d H:i:s');
        $this->connection->insert($this->t('recepcionproveedor'), [
            'id_shop' => $idShop,
            'status' => 'draft',
            'id_employee' => $idEmployee,
            'date_add' => $now,
            'date_upd' => $now,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function updateHeader(int $id, ?int $idSupplier, ?string $number, ?string $date, ?string $notes): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . $this->t('recepcionproveedor') . '
             SET id_supplier = :supplier, receipt_number = :number, receipt_date = :date,
                 notes = :notes, date_upd = :now
             WHERE id_reception = :id AND status = \'draft\'',
            [
                'supplier' => $idSupplier,
                'number' => $number,
                'date' => $date,
                'notes' => $notes,
                'now' => date('Y-m-d H:i:s'),
                'id' => $id,
            ]
        );
    }

    public function deleteDraft(int $id): bool
    {
        // Las líneas se eliminan en cascada (FK).
        return 1 === $this->connection->executeStatement(
            'DELETE FROM ' . $this->t('recepcionproveedor') . ' WHERE id_reception = :id AND status = \'draft\'',
            ['id' => $id]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLines(int $idReception, int $idLang): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT l.id_line, l.id_product, l.id_product_attribute, l.quantity, l.unit_cost,
                    p.reference AS product_reference, pa.reference AS combination_reference
             FROM ' . $this->t('recepcionproveedor_line') . ' l
             LEFT JOIN ' . $this->t('product') . ' p ON p.id_product = l.id_product
             LEFT JOIN ' . $this->t('product_attribute') . ' pa ON pa.id_product_attribute = l.id_product_attribute
             WHERE l.id_reception = :id
             ORDER BY l.id_line',
            ['id' => $idReception]
        );

        foreach ($rows as &$row) {
            $idPa = (int) $row['id_product_attribute'];
            $row['quantity'] = (int) $row['quantity'];
            $row['unit_cost'] = (float) $row['unit_cost'];
            $row['reference'] = ($row['combination_reference'] ?: $row['product_reference']) ?: '';
            $row['name'] = (string) \Product::getProductName((int) $row['id_product'], $idPa ?: null, $idLang);
        }
        unset($row);

        return $rows;
    }

    /**
     * Añade una línea; si el producto/combinación ya está, suma la cantidad y
     * sustituye el costo por el último introducido.
     *
     * @throws \InvalidArgumentException si el producto o la combinación no existen
     */
    public function addLine(int $idReception, int $idProduct, int $idProductAttribute, int $quantity, float $unitCost): void
    {
        $exists = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->t('product') . ' WHERE id_product = :p',
            ['p' => $idProduct]
        );
        if (!$exists) {
            throw new \InvalidArgumentException('product_not_found');
        }

        $combinations = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->t('product_attribute') . ' WHERE id_product = :p',
            ['p' => $idProduct]
        );
        if ($idProductAttribute > 0) {
            $belongs = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ' . $this->t('product_attribute') . '
                 WHERE id_product_attribute = :pa AND id_product = :p',
                ['pa' => $idProductAttribute, 'p' => $idProduct]
            );
            if (!$belongs) {
                throw new \InvalidArgumentException('combination_not_found');
            }
        } elseif ($combinations > 0) {
            throw new \InvalidArgumentException('combination_required');
        }

        $this->connection->executeStatement(
            'INSERT INTO ' . $this->t('recepcionproveedor_line') . '
                (id_reception, id_product, id_product_attribute, quantity, unit_cost)
             VALUES (:r, :p, :pa, :q, :c)
             ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), unit_cost = VALUES(unit_cost)',
            ['r' => $idReception, 'p' => $idProduct, 'pa' => $idProductAttribute, 'q' => $quantity, 'c' => $unitCost]
        );
        $this->touch($idReception);
    }

    public function updateLine(int $idReception, int $idLine, int $quantity, float $unitCost): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . $this->t('recepcionproveedor_line') . '
             SET quantity = :q, unit_cost = :c
             WHERE id_line = :l AND id_reception = :r',
            ['q' => $quantity, 'c' => $unitCost, 'l' => $idLine, 'r' => $idReception]
        );
        $this->touch($idReception);
    }

    public function deleteLine(int $idReception, int $idLine): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ' . $this->t('recepcionproveedor_line') . ' WHERE id_line = :l AND id_reception = :r',
            ['l' => $idLine, 'r' => $idReception]
        );
        $this->touch($idReception);
    }

    private function touch(int $idReception): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . $this->t('recepcionproveedor') . ' SET date_upd = :now WHERE id_reception = :id',
            ['now' => date('Y-m-d H:i:s'), 'id' => $idReception]
        );
    }

    public function isActiveSupplierForShop(int $idSupplier, int $idShop): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->t('supplier') . ' s
             INNER JOIN ' . $this->t('supplier_shop') . ' ss ON ss.id_supplier = s.id_supplier
             WHERE s.id_supplier = :supplier AND s.active = 1 AND ss.id_shop = :shop',
            ['supplier' => $idSupplier, 'shop' => $idShop]
        );
    }

    /** @return array<int, array{id_supplier:int, name:string}> */
    public function searchSuppliers(string $query, int $idShop, int $limit = 20): array
    {
        $like = '%' . addcslashes($query, '%_\\') . '%';

        return $this->connection->fetchAllAssociative(
            'SELECT s.id_supplier, s.name
             FROM ' . $this->t('supplier') . ' s
             INNER JOIN ' . $this->t('supplier_shop') . ' ss ON ss.id_supplier = s.id_supplier AND ss.id_shop = :shop
             WHERE s.active = 1 AND s.name LIKE :query
             ORDER BY s.name ASC
             LIMIT ' . (int) $limit,
            ['shop' => $idShop, 'query' => $like]
        );
    }

    /**
     * Busca productos y combinaciones por nombre, referencia, EAN o ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchProducts(string $query, int $idLang, int $idShop, int $limit = 20): array
    {
        $like = '%' . addcslashes($query, '%_\\') . '%';

        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id_product, COALESCE(pa.id_product_attribute, 0) AS id_product_attribute,
                    p.reference AS product_reference, pa.reference AS combination_reference,
                    ps.wholesale_price
             FROM ' . $this->t('product') . ' p
             INNER JOIN ' . $this->t('product_shop') . ' ps ON ps.id_product = p.id_product AND ps.id_shop = :shop
             INNER JOIN ' . $this->t('product_lang') . ' pl
                     ON pl.id_product = p.id_product AND pl.id_shop = :shop AND pl.id_lang = :lang
             LEFT JOIN ' . $this->t('product_attribute') . ' pa ON pa.id_product = p.id_product
             WHERE pl.name LIKE :q1 OR p.reference LIKE :q2 OR pa.reference LIKE :q3
                OR p.ean13 LIKE :q4 OR pa.ean13 LIKE :q5 OR p.id_product = :exact
             ORDER BY pl.name ASC, pa.id_product_attribute ASC
             LIMIT ' . (int) $limit,
            [
                'shop' => $idShop,
                'lang' => $idLang,
                'q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like,
                'exact' => ctype_digit($query) ? (int) $query : 0,
            ]
        );

        $results = [];
        foreach ($rows as $row) {
            $idPa = (int) $row['id_product_attribute'];
            $results[] = [
                'id_product' => (int) $row['id_product'],
                'id_product_attribute' => $idPa,
                'label' => (string) \Product::getProductName((int) $row['id_product'], $idPa ?: null, $idLang),
                'reference' => ($row['combination_reference'] ?: $row['product_reference']) ?: '',
                'cost' => (float) $row['wholesale_price'],
            ];
        }

        return $results;
    }
}
