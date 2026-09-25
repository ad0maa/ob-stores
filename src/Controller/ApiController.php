<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\HttpError;
use App\Http\Response;
use App\Repo\StockRepo;
use App\Service\ReceiveConsumable;
use App\Service\ValidationError;
use PDO;

/** The JSON API both front-end generations call. */
final class ApiController
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function stock(): Response
    {
        $stock = new StockRepo($this->db);

        return Response::json(['gear' => $stock->gearSummary(), 'consumables' => $stock->consumables()]);
    }

    public function receive(): Response
    {
        $input = json_body();
        $consumableId = self::int($input, 'consumable_id');

        $lotId = new ReceiveConsumable($this->db)->receive(
            $consumableId,
            is_string($input['lot_code'] ?? null) ? $input['lot_code'] : '',
            self::int($input, 'qty'),
            is_string($input['received_on'] ?? null) ? $input['received_on'] : '',
        );

        $consumable = new StockRepo($this->db)->consumable($consumableId) ?? throw new HttpError(404, 'Consumable not found');

        return Response::json(['lot_id' => $lotId, 'consumable' => $consumable], 201);
    }

    /** @param array<string, mixed> $input */
    public static function int(array $input, string $field): int
    {
        $value = filter_var($input[$field] ?? null, FILTER_VALIDATE_INT);

        return $value === false ? throw new ValidationError([$field => 'Must be a whole number.']) : $value;
    }
}
