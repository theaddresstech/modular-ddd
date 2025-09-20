<?php

declare(strict_types=1);

namespace LaravelModularDDD\Testing\Traits;

use Faker\Factory as FakerFactory;
use Faker\Generator as Faker;

/**
 * GeneratesTestData
 *
 * Provides utilities for generating realistic test data that respects
 * domain constraints and business rules.
 */
trait GeneratesTestData
{
    private ?Faker $faker = null;

    /**
     * Get Faker instance.
     */
    protected function faker(): Faker
    {
        if ($this->faker === null) {
            $this->faker = FakerFactory::create();
        }

        return $this->faker;
    }

    /**
     * Generate a unique ID.
     */
    protected function generateId(): string
    {
        return $this->faker()->uuid;
    }

    /**
     * Generate test email address.
     */
    protected function generateEmail(): string
    {
        return $this->faker()->unique()->safeEmail;
    }

    /**
     * Generate test name.
     */
    protected function generateName(): string
    {
        return $this->faker()->name;
    }

    /**
     * Generate test company name.
     */
    protected function generateCompanyName(): string
    {
        return $this->faker()->company;
    }

    /**
     * Generate test address.
     */
    protected function generateAddress(): array
    {
        return [
            'street' => $this->faker()->streetAddress,
            'city' => $this->faker()->city,
            'state' => $this->faker()->state,
            'postal_code' => $this->faker()->postcode,
            'country' => $this->faker()->country,
        ];
    }

    /**
     * Generate test phone number.
     */
    protected function generatePhoneNumber(): string
    {
        return $this->faker()->phoneNumber;
    }

    /**
     * Generate test date in the past.
     */
    protected function generatePastDate(string $maxAge = '-1 year'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->faker()->dateTimeBetween($maxAge, 'now')->format('Y-m-d H:i:s'));
    }

    /**
     * Generate test date in the future.
     */
    protected function generateFutureDate(string $maxAge = '+1 year'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->faker()->dateTimeBetween('now', $maxAge)->format('Y-m-d H:i:s'));
    }

    /**
     * Generate test price.
     */
    protected function generatePrice(float $min = 1.00, float $max = 1000.00): float
    {
        return $this->faker()->randomFloat(2, $min, $max);
    }

    /**
     * Generate test quantity.
     */
    protected function generateQuantity(int $min = 1, int $max = 100): int
    {
        return $this->faker()->numberBetween($min, $max);
    }

    /**
     * Generate test percentage.
     */
    protected function generatePercentage(): float
    {
        return $this->faker()->randomFloat(2, 0, 100);
    }

    /**
     * Generate test description.
     */
    protected function generateDescription(int $sentences = 3): string
    {
        return $this->faker()->text($sentences * 50);
    }

    /**
     * Generate test URL.
     */
    protected function generateUrl(): string
    {
        return $this->faker()->url;
    }

    /**
     * Generate test IP address.
     */
    protected function generateIpAddress(): string
    {
        return $this->faker()->ipv4;
    }

    /**
     * Generate test user agent.
     */
    protected function generateUserAgent(): string
    {
        return $this->faker()->userAgent;
    }

    /**
     * Generate test file name.
     */
    protected function generateFileName(string $extension = 'txt'): string
    {
        return $this->faker()->word . '.' . $extension;
    }

    /**
     * Generate test file size in bytes.
     */
    protected function generateFileSize(int $min = 1024, int $max = 10485760): int
    {
        return $this->faker()->numberBetween($min, $max);
    }

    /**
     * Generate test color hex code.
     */
    protected function generateColor(): string
    {
        return $this->faker()->hexColor;
    }

    /**
     * Generate test username.
     */
    protected function generateUsername(): string
    {
        return $this->faker()->unique()->userName;
    }

    /**
     * Generate test password.
     */
    protected function generatePassword(int $length = 12): string
    {
        return $this->faker()->password($length, $length);
    }

    /**
     * Generate test credit card number.
     */
    protected function generateCreditCardNumber(): string
    {
        return $this->faker()->creditCardNumber;
    }

    /**
     * Generate test bank account number.
     */
    protected function generateBankAccountNumber(): string
    {
        return $this->faker()->bankAccountNumber;
    }

    /**
     * Generate test latitude.
     */
    protected function generateLatitude(): float
    {
        return $this->faker()->latitude;
    }

    /**
     * Generate test longitude.
     */
    protected function generateLongitude(): float
    {
        return $this->faker()->longitude;
    }

    /**
     * Generate test locale.
     */
    protected function generateLocale(): string
    {
        return $this->faker()->locale;
    }

    /**
     * Generate test currency code.
     */
    protected function generateCurrencyCode(): string
    {
        return $this->faker()->currencyCode;
    }

    /**
     * Generate test country code.
     */
    protected function generateCountryCode(): string
    {
        return $this->faker()->countryCode;
    }

    /**
     * Generate test timezone.
     */
    protected function generateTimezone(): string
    {
        return $this->faker()->timezone;
    }

    /**
     * Generate array of test data.
     */
    protected function generateArray(callable $generator, int $count): array
    {
        $data = [];
        for ($i = 0; $i < $count; $i++) {
            $data[] = $generator();
        }
        return $data;
    }

    /**
     * Generate test data based on type.
     */
    protected function generateByType(string $type): mixed
    {
        return match (strtolower($type)) {
            'string' => $this->faker()->word,
            'text' => $this->faker()->text,
            'int', 'integer' => $this->faker()->numberBetween(1, 1000),
            'float', 'double' => $this->faker()->randomFloat(2, 1, 1000),
            'bool', 'boolean' => $this->faker()->boolean,
            'email' => $this->generateEmail(),
            'name' => $this->generateName(),
            'phone' => $this->generatePhoneNumber(),
            'url' => $this->generateUrl(),
            'date' => $this->generatePastDate(),
            'datetime' => $this->generatePastDate(),
            'timestamp' => $this->generatePastDate()->getTimestamp(),
            'price' => $this->generatePrice(),
            'quantity' => $this->generateQuantity(),
            'percentage' => $this->generatePercentage(),
            'uuid' => $this->generateId(),
            'id' => $this->generateId(),
            default => $this->faker()->word,
        };
    }

    /**
     * Generate value object test data.
     */
    protected function generateValueObject(string $valueObjectType): mixed
    {
        return match (strtolower($valueObjectType)) {
            'email' => $this->generateEmail(),
            'name' => $this->generateName(),
            'phone' => $this->generatePhoneNumber(),
            'address' => $this->generateAddress(),
            'price' => $this->generatePrice(),
            'quantity' => $this->generateQuantity(),
            'percentage' => $this->generatePercentage(),
            'url' => $this->generateUrl(),
            'color' => $this->generateColor(),
            'date' => $this->generatePastDate(),
            'ipaddress' => $this->generateIpAddress(),
            'username' => $this->generateUsername(),
            'locale' => $this->generateLocale(),
            'currency' => $this->generateCurrencyCode(),
            'country' => $this->generateCountryCode(),
            'timezone' => $this->generateTimezone(),
            default => $this->faker()->word,
        };
    }

    /**
     * Generate aggregate test data.
     */
    protected function generateAggregateData(string $aggregateType): array
    {
        $baseData = [
            'id' => $this->generateId(),
            'created_at' => $this->generatePastDate(),
            'updated_at' => $this->generatePastDate(),
        ];

        $specificData = match (strtolower($aggregateType)) {
            'user' => [
                'name' => $this->generateName(),
                'email' => $this->generateEmail(),
                'username' => $this->generateUsername(),
                'phone' => $this->generatePhoneNumber(),
            ],
            'order' => [
                'order_number' => 'ORD-' . $this->faker()->unique()->numberBetween(10000, 99999),
                'total' => $this->generatePrice(),
                'status' => $this->faker()->randomElement(['pending', 'confirmed', 'shipped', 'delivered']),
                'customer_id' => $this->generateId(),
            ],
            'product' => [
                'name' => $this->faker()->words(3, true),
                'description' => $this->generateDescription(),
                'price' => $this->generatePrice(),
                'sku' => $this->faker()->unique()->regexify('[A-Z]{3}-[0-9]{4}'),
                'category' => $this->faker()->word,
            ],
            'payment' => [
                'amount' => $this->generatePrice(),
                'currency' => $this->generateCurrencyCode(),
                'status' => $this->faker()->randomElement(['pending', 'completed', 'failed', 'refunded']),
                'payment_method' => $this->faker()->randomElement(['credit_card', 'paypal', 'bank_transfer']),
            ],
            default => [
                'name' => $this->faker()->words(2, true),
                'description' => $this->generateDescription(),
                'status' => $this->faker()->randomElement(['active', 'inactive']),
            ],
        };

        return array_merge($baseData, $specificData);
    }

    /**
     * Generate command test data.
     */
    protected function generateCommandData(string $commandType): array
    {
        return match (true) {
            str_contains(strtolower($commandType), 'create') => [
                'id' => $this->generateId(),
                'name' => $this->generateName(),
                'data' => $this->faker()->words(5, true),
            ],
            str_contains(strtolower($commandType), 'update') => [
                'id' => $this->generateId(),
                'name' => $this->generateName(),
                'data' => $this->faker()->words(5, true),
            ],
            str_contains(strtolower($commandType), 'delete') => [
                'id' => $this->generateId(),
            ],
            default => [
                'id' => $this->generateId(),
                'data' => $this->faker()->words(3, true),
            ],
        };
    }

    /**
     * Generate query test data.
     */
    protected function generateQueryData(string $queryType): array
    {
        return match (true) {
            str_contains(strtolower($queryType), 'list') => [
                'page' => $this->faker()->numberBetween(1, 10),
                'limit' => $this->faker()->numberBetween(10, 100),
                'filters' => [],
            ],
            str_contains(strtolower($queryType), 'find') => [
                'id' => $this->generateId(),
            ],
            str_contains(strtolower($queryType), 'search') => [
                'query' => $this->faker()->words(3, true),
                'filters' => [],
            ],
            default => [
                'criteria' => $this->faker()->words(2, true),
            ],
        };
    }

    /**
     * Generate event test data.
     */
    protected function generateEventData(string $eventType): array
    {
        $baseData = [
            'aggregate_id' => $this->generateId(),
            'version' => $this->faker()->numberBetween(1, 10),
            'occurred_at' => $this->generatePastDate(),
        ];

        $specificData = match (true) {
            str_contains(strtolower($eventType), 'created') => [
                'name' => $this->generateName(),
                'email' => $this->generateEmail(),
            ],
            str_contains(strtolower($eventType), 'updated') => [
                'changes' => [
                    'name' => $this->generateName(),
                    'updated_at' => $this->generatePastDate(),
                ],
            ],
            str_contains(strtolower($eventType), 'deleted') => [
                'deleted_at' => $this->generatePastDate(),
            ],
            default => [
                'data' => $this->faker()->words(5, true),
            ],
        };

        return array_merge($baseData, $specificData);
    }

    /**
     * Generate collection of test data.
     */
    protected function generateCollection(callable $generator, int $min = 1, int $max = 5): array
    {
        $count = $this->faker()->numberBetween($min, $max);
        return $this->generateArray($generator, $count);
    }

    /**
     * Generate weighted random choice.
     */
    protected function generateWeightedChoice(array $choices): mixed
    {
        return $this->faker()->randomElement($choices);
    }

    /**
     * Generate sequential data.
     */
    protected function generateSequence(string $prefix, int $start = 1): \Generator
    {
        $counter = $start;
        while (true) {
            yield $prefix . str_pad((string) $counter, 4, '0', STR_PAD_LEFT);
            $counter++;
        }
    }

    /**
     * Generate realistic business data.
     */
    protected function generateBusinessScenarioData(string $scenario): array
    {
        return match (strtolower($scenario)) {
            'user_registration' => [
                'name' => $this->generateName(),
                'email' => $this->generateEmail(),
                'password' => $this->generatePassword(),
                'terms_accepted' => true,
                'marketing_opt_in' => $this->faker()->boolean,
            ],
            'order_placement' => [
                'customer_id' => $this->generateId(),
                'items' => $this->generateCollection(fn() => [
                    'product_id' => $this->generateId(),
                    'quantity' => $this->generateQuantity(1, 5),
                    'price' => $this->generatePrice(),
                ], 1, 3),
                'shipping_address' => $this->generateAddress(),
                'payment_method' => $this->faker()->randomElement(['credit_card', 'paypal']),
            ],
            'payment_processing' => [
                'amount' => $this->generatePrice(),
                'currency' => $this->generateCurrencyCode(),
                'card_number' => $this->generateCreditCardNumber(),
                'expiry_date' => $this->generateFutureDate('+2 years'),
            ],
            default => [
                'scenario' => $scenario,
                'data' => $this->faker()->words(5, true),
            ],
        };
    }
}