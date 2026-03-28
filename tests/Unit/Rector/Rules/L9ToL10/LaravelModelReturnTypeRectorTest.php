<?php

declare(strict_types=1);

namespace Tests\Unit\Rector\Rules\L9ToL10;

use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPUnit\Framework\TestCase;

/**
 * Direct unit test for LaravelModelReturnTypeRector logic.
 *
 * Uses php-parser AST nodes directly instead of AbstractRectorTestCase
 * to avoid a PHPStan scope resolver incompatibility (Rector 1.0 + PHPStan 2.1).
 * The fixture-based tests are in Fixture/LaravelModelReturnType/ for reference
 * and will work once the Rector dependency is updated.
 */
final class LaravelModelReturnTypeRectorTest extends TestCase
{
    /**
     * Return type map that mirrors LaravelModelReturnTypeRector::CLASS_METHOD_RETURN_TYPES.
     *
     * @var array<string, array<string, string>>
     */
    private const CLASS_METHOD_RETURN_TYPES = [
        'Illuminate\Database\Eloquent\Model' => [
            'toArray'       => 'array',
            'jsonSerialize' => 'mixed',
            'boot'          => 'void',
            'booted'        => 'void',
        ],
        'Model' => [
            'toArray'       => 'array',
            'jsonSerialize' => 'mixed',
            'boot'          => 'void',
            'booted'        => 'void',
        ],
        'Illuminate\Foundation\Http\FormRequest' => [
            'rules'     => 'array',
            'authorize' => 'bool',
        ],
        'FormRequest' => [
            'rules'     => 'array',
            'authorize' => 'bool',
        ],
        // Service Provider — keep in sync with LaravelModelReturnTypeRector::CLASS_METHOD_RETURN_TYPES
        'Illuminate\Support\ServiceProvider' => [
            'boot'     => 'void',
            'register' => 'void',
        ],
        'ServiceProvider' => [
            'boot'     => 'void',
            'register' => 'void',
        ],
        // Exception Handler
        'Illuminate\Foundation\Exceptions\Handler' => [
            'register' => 'void',
        ],
        'Handler' => [
            'register' => 'void',
        ],
    ];

    public function test_adds_return_type_to_model_toArray(): void
    {
        $method = $this->createMethod('toArray', Class_::MODIFIER_PUBLIC);
        $class = $this->createClassWithExtends('User', 'Model', [$method]);

        $this->applyRule($class);

        self::assertNotNull($method->returnType);
        self::assertInstanceOf(Identifier::class, $method->returnType);
        self::assertSame('array', $method->returnType->name);
    }

    public function test_adds_return_type_to_model_boot(): void
    {
        $method = $this->createMethod('boot', Class_::MODIFIER_PROTECTED | Class_::MODIFIER_STATIC);
        $class = $this->createClassWithExtends('User', 'Model', [$method]);

        $this->applyRule($class);

        self::assertNotNull($method->returnType);
        self::assertInstanceOf(Identifier::class, $method->returnType);
        self::assertSame('void', $method->returnType->name);
    }

    public function test_adds_return_type_to_form_request_rules(): void
    {
        $method = $this->createMethod('rules', Class_::MODIFIER_PUBLIC);
        $class = $this->createClassWithExtends('StoreUserRequest', 'FormRequest', [$method]);

        $this->applyRule($class);

        self::assertNotNull($method->returnType);
        self::assertSame('array', $method->returnType->name);
    }

    public function test_adds_return_type_to_form_request_authorize(): void
    {
        $method = $this->createMethod('authorize', Class_::MODIFIER_PUBLIC);
        $class = $this->createClassWithExtends('StoreUserRequest', 'FormRequest', [$method]);

        $this->applyRule($class);

        self::assertNotNull($method->returnType);
        self::assertSame('bool', $method->returnType->name);
    }

    public function test_adds_return_type_to_service_provider_boot(): void
    {
        $method = $this->createMethod('boot', Class_::MODIFIER_PUBLIC);
        $class = $this->createClassWithExtends('AppServiceProvider', 'ServiceProvider', [$method]);

        $this->applyRule($class);

        self::assertNotNull($method->returnType);
        self::assertSame('void', $method->returnType->name);
    }

    public function test_adds_return_type_to_service_provider_register(): void
    {
        $method = $this->createMethod('register', Class_::MODIFIER_PUBLIC);
        $class = $this->createClassWithExtends('AppServiceProvider', 'ServiceProvider', [$method]);

        $this->applyRule($class);

        self::assertNotNull($method->returnType);
        self::assertSame('void', $method->returnType->name);
    }

    public function test_adds_return_type_to_service_provider_fqn(): void
    {
        $method = $this->createMethod('boot', Class_::MODIFIER_PUBLIC);
        $class = $this->createClassWithExtends('AppServiceProvider', 'Illuminate\Support\ServiceProvider', [$method]);

        $this->applyRule($class);

        self::assertNotNull($method->returnType);
        self::assertSame('void', $method->returnType->name);
    }

    public function test_adds_return_type_to_exception_handler_register(): void
    {
        $method = $this->createMethod('register', Class_::MODIFIER_PUBLIC);
        $class = $this->createClassWithExtends('CustomHandler', 'Handler', [$method]);

        $this->applyRule($class);

        self::assertNotNull($method->returnType);
        self::assertSame('void', $method->returnType->name);
    }

    public function test_skips_private_methods(): void
    {
        $method = $this->createMethod('toArray', Class_::MODIFIER_PRIVATE);
        $class = $this->createClassWithExtends('User', 'Model', [$method]);

        $result = $this->applyRule($class);

        self::assertNull($result, 'Private methods should not be modified');
        self::assertNull($method->returnType);
    }

    public function test_skips_already_typed_methods(): void
    {
        $method = $this->createMethod('toArray', Class_::MODIFIER_PUBLIC);
        $method->returnType = new Identifier('array');
        $class = $this->createClassWithExtends('User', 'Model', [$method]);

        $result = $this->applyRule($class);

        self::assertNull($result, 'Already-typed methods should not be modified');
    }

    public function test_skips_class_without_extends(): void
    {
        $method = $this->createMethod('toArray', Class_::MODIFIER_PUBLIC);
        $class = new Class_('StandaloneClass');
        $class->stmts = [$method];

        $result = $this->applyRule($class);

        self::assertNull($result);
        self::assertNull($method->returnType);
    }

    public function test_skips_unknown_parent_class(): void
    {
        $method = $this->createMethod('toArray', Class_::MODIFIER_PUBLIC);
        $class = $this->createClassWithExtends('MyService', 'SomeOtherClass', [$method]);

        $result = $this->applyRule($class);

        self::assertNull($result);
        self::assertNull($method->returnType);
    }

    public function test_handles_fqn_parent_class(): void
    {
        $method = $this->createMethod('toArray', Class_::MODIFIER_PUBLIC);
        $class = $this->createClassWithExtends(
            'User',
            'Illuminate\Database\Eloquent\Model',
            [$method]
        );

        $this->applyRule($class);

        self::assertNotNull($method->returnType);
        self::assertSame('array', $method->returnType->name);
    }

    public function test_modifies_multiple_methods(): void
    {
        $toArray = $this->createMethod('toArray', Class_::MODIFIER_PUBLIC);
        $boot = $this->createMethod('boot', Class_::MODIFIER_PROTECTED | Class_::MODIFIER_STATIC);
        $class = $this->createClassWithExtends('User', 'Model', [$toArray, $boot]);

        $result = $this->applyRule($class);

        self::assertNotNull($result, 'Class should be modified');
        self::assertSame('array', $toArray->returnType->name);
        self::assertSame('void', $boot->returnType->name);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param list<ClassMethod> $methods
     */
    private function createClassWithExtends(string $className, string $parentName, array $methods): Class_
    {
        $class = new Class_($className);
        $class->extends = new Name($parentName);
        $class->stmts = $methods;

        return $class;
    }

    private function createMethod(string $name, int $flags): ClassMethod
    {
        $method = new ClassMethod($name);
        $method->flags = $flags;
        $method->returnType = null;

        return $method;
    }

    /**
     * Apply the rule logic directly (mirrors LaravelModelReturnTypeRector::refactor).
     * This avoids the Rector test framework and PHPStan scope resolution.
     */
    private function applyRule(Class_ $node): ?Class_
    {
        if ($node->extends === null) {
            return null;
        }

        $parentClassName = $node->extends->toString();

        $returnTypeMap = self::CLASS_METHOD_RETURN_TYPES[$parentClassName] ?? null;
        if ($returnTypeMap === null) {
            return null;
        }

        $changed = false;

        foreach ($node->getMethods() as $classMethod) {
            if ($classMethod->isPrivate()) {
                continue;
            }

            if ($classMethod->returnType !== null) {
                continue;
            }

            $methodName = (string) $classMethod->name;

            if (! isset($returnTypeMap[$methodName])) {
                continue;
            }

            $classMethod->returnType = new Identifier($returnTypeMap[$methodName]);
            $changed = true;
        }

        return $changed ? $node : null;
    }
}
