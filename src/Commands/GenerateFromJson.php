<?php


namespace Qoenut\GenerateFromJson\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class GenerateFromJson extends Command
{
    protected $signature = 'generate:from-json';
    protected $description = 'Generate models, migrations, and pivot models from JSON structure';

    public function handle()
    {
        $json = storage_path('app/model_structure.json');
        if (!File::exists($json)) {
            $this->error('File model_structure.json tidak ditemukan di storage/app/');
            return;
        }

        $data = json_decode(File::get($json), true);
        $models = $data['models'] ?? [];
        $relations = $data['relations'] ?? [];

        foreach ($models as $model => $fields) {
            $this->generateModel($model, $fields, $relations);
            $this->generateMigration($model, $fields);
        }

        $this->generatePivotTables($relations['belongsToMany'] ?? []);
        $this->generatePivotModels($relations['belongsToMany'] ?? []);

        $this->info('Semua file berhasil digenerate.');
    }

    protected function generateModel($modelName, $fields = [], $allRelations = [])
    {
        $className = Str::studly($modelName);

        echo $className;
        $modelPath = app_path("Models/$className.php");

        $fillableFields = [];
        foreach ($fields as $field) {
            $fieldParts = explode(':', $field);
            $fillableFields[] = $fieldParts[0];
        }

        $fillableCode = implode(",\n        ", array_map(fn($f) => "'$f'", $fillableFields));

        $relationshipMethods = '';

        foreach ($allRelations['hasMany'] ?? [] as $relation) {
            if (array_key_first($relation) === $modelName) {
                $related = array_values($relation)[0];
                $relationshipMethods .= $this->generateHasMany(Str::studly($related), $related);
            }
        }

        foreach ($allRelations['belongsTo'] ?? [] as $relation) {
            if (array_key_first($relation) === $modelName) {
                $related = array_values($relation)[0];
                $relationshipMethods .= $this->generateBelongsTo(Str::studly($related), $related);
            }
        }

        foreach ($allRelations['belongsToMany'] ?? [] as $relation) {
            if (array_key_exists($modelName, $relation)) {
                $related = $relation[$modelName];
                $relationshipMethods .= $this->generateBelongsToMany(Str::studly($related), $related);
            } elseif (in_array($modelName, array_values($relation))) {
                $related = array_key_first($relation);
                $relationshipMethods .= $this->generateBelongsToMany(Str::studly($related), $related);
            }
        }

        if (!File::exists($modelPath)) {
            $modelContent = <<<EOT
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;
            class $className extends Model
            {
                protected \$fillable = [
                    $fillableCode
                ];

            $relationshipMethods}
            EOT;
            File::put($modelPath, $modelContent);
        } else {

          $modelContent = <<<EOT
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;
            class $className extends Model
            {
                protected \$fillable = [
                    $fillableCode
                ];

            $relationshipMethods}
            EOT;
            File::put($modelPath, $modelContent);


        }
    }

    protected function generateMigration($modelName, $fields)
    {
        $tableName = Str::plural($modelName);

        if (!Schema::hasTable($tableName)) {
            // Create table migration

            $this->createMigrationFile($tableName, $fields, 'create');
        } else {
            // Table exists, check for new columns
            $existingColumns = Schema::getColumnListing($tableName);
            $newFields = [];

            foreach ($fields as $fieldString) {
                $field = explode(':', $fieldString)[0];
                if (!in_array($field, $existingColumns)) {
                    $newFields[] = $fieldString;
                }
            }

            if (!empty($newFields)) {
                $this->createMigrationFile($tableName, $newFields, 'add');
            }
        }
    }

    protected function createMigrationFile($tableName, $fields, $type = 'create')
    {
        $timestamp = now()->format('Y_m_d_His');
        $fieldNames = implode('_', array_map(fn($f) => explode(':', $f)[0], $fields));
        $migrationName = $type === 'create' ? "create_{$tableName}_table" : "add_{$fieldNames}_to_{$tableName}_table";

        foreach (File::files(database_path('migrations')) as $file) {
            if (Str::contains($file->getFilename(), $migrationName)) {
                File::delete($file->getPathname());
            }
        }
        $filename = database_path("migrations/{$timestamp}_{$migrationName}.php");

        $fieldsString = '';
        foreach ($fields as $fieldString) {
            [$field, $typeWithModifiers] = explode(':', $fieldString, 2);
            $modifiers = explode('|', $typeWithModifiers);
            $type = array_shift($modifiers);

            if ($type === 'foreign') {
                $fieldsString .= "\$table->unsignedBigInteger('$field');\n            ";
                $refTable = Str::plural(str_replace('_id', '', $field));
                $fieldsString .= "\$table->foreign('$field')->references('id')->on('$refTable');\n            ";
                continue;
            }

            if (preg_match('/decimal\\((\\d+),(\\d+)\\)/', $type, $matches)) {
                $line = "\$table->decimal('$field', {$matches[1]}, {$matches[2]})";
            } else {
                $line = "\$table->$type('$field')";
            }

            foreach ($modifiers as $modifier) {
                if ($modifier === 'nullable') {
                    $line .= '->nullable()';
                } elseif ($modifier === 'unique') {
                    $line .= '->unique()';
                } elseif (str_starts_with($modifier, 'default:')) {
                    $default = substr($modifier, 8);
                    $line .= is_numeric($default) ? "->default($default)" : "->default('$default')";
                }
            }

            $fieldsString .= $line . ";\n            ";
        }

        if ($type === 'create') {
            $upMethod = <<<EOT
            Schema::create('$tableName', function (Blueprint \$table) {
                \$table->id();
                $fieldsString\$table->timestamps();
            });
            EOT;
        } else {
            $upMethod = <<<EOT
            Schema::table('$tableName', function (Blueprint \$table) {
                $fieldsString
            });
            EOT;
        }

        $migrationTemplate = <<<EOT
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration {
            public function up(): void
            {
                $upMethod
            }

            public function down(): void
            {
                // Optional: rollback logic untuk drop kolom (bisa dikembangkan)
            }
        };
        EOT;

        File::put($filename, $migrationTemplate);
    }

    protected function generatePivotTables(array $pivotRelations)
    {
        foreach ($pivotRelations as $relation) {
            foreach ($relation as $modelA => $modelB) {
                $table1 = Str::snake(Str::singular($modelA));
                $table2 = Str::snake(Str::singular($modelB));
                $tables = collect([$table1, $table2])
                    ->sort()
                    ->values();
                $pivotTable = $tables->implode('_');
                foreach (File::files(database_path('migrations')) as $file) {
                    if (Str::contains($file->getFilename(), $pivotTable)) {
                        File::delete($file->getPathname());
                    }
                }

                if (!Schema::hasTable($pivotTable)) {
                    $timestamp = now()->addSeconds(rand(1, 60))->format('Y_m_d_His');
                    $migrationName = 'create_' . $pivotTable . '_table';
                    $filename = database_path("migrations/{$timestamp}_{$migrationName}.php");

                    $migrationTemplate = <<<EOT
                    <?php

                    use Illuminate\Database\Migrations\Migration;
                    use Illuminate\Database\Schema\Blueprint;
                    use Illuminate\Support\Facades\Schema;

                    return new class extends Migration {
                        public function up(): void
                        {
                            Schema::create('$pivotTable', function (Blueprint \$table) {
                                \$table->id();
                                \$table->unsignedBigInteger('{$tables[0]}_id');
                                \$table->unsignedBigInteger('{$tables[1]}_id');

                                \$table->foreign('{$tables[0]}_id')->references('id')->on('{$tables[0]}s')->onDelete('cascade');
                                \$table->foreign('{$tables[1]}_id')->references('id')->on('{$tables[1]}s')->onDelete('cascade');
                            });
                        }

                        public function down(): void
                        {
                            Schema::dropIfExists('$pivotTable');
                        }
                    };
                    EOT;

                    File::put($filename, $migrationTemplate);
                }
            }
        }
    }

    protected function generatePivotModels(array $pivotRelations)
    {
        foreach ($pivotRelations as $relation) {
            foreach ($relation as $modelA => $modelB) {
                $names = collect([Str::singular($modelA), Str::singular($modelB)])
                    ->sort()
                    ->values();
                $pivotModelName = Str::studly($names->implode('_'));
                $pivotTable = $names->implode('_');

                $field1 = $names[0] . '_id';
                $field2 = $names[1] . '_id';

                $modelPath = app_path("Models/{$pivotModelName}.php");

                if (!File::exists($modelPath)) {
                    $modelContent = <<<EOT
                    <?php

                    namespace App\Models;

                    use Illuminate\Database\Eloquent\Relations\Pivot;

                    class $pivotModelName extends Pivot
                    {
                        protected \$table = '$pivotTable';

                        protected \$fillable = [
                            '$field1',
                            '$field2',
                        ];
                    }
                    EOT;

                    File::put($modelPath, $modelContent);
                }
            }
        }
    }

    protected function generateHasMany($relatedClass, $relatedTable)
    {
        $method = Str::camel(Str::plural($relatedTable));
        return <<<EOT

            public function $method()
            {
                return \$this->hasMany($relatedClass::class);
            }

        EOT;
    }

    protected function generateBelongsTo($relatedClass, $relatedTable)
    {
        $method = Str::camel($relatedTable);
        return <<<EOT

            public function $method()
            {
                return \$this->belongsTo($relatedClass::class);
            }

        EOT;
    }

    protected function generateBelongsToMany($relatedClass, $relatedTable)
    {
        $method = Str::camel(Str::plural($relatedTable));
        return <<<EOT

            public function $method()
            {
                return \$this->belongsToMany($relatedClass::class);
            }

        EOT;
    }
}
