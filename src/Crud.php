<?php
namespace DataTable\CrudGenerator;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Crud extends Command
{
    protected $signature   = 'make:crud {name}';
    protected $description = 'Generate CRUD based on an existing model with separate request files';

    protected $enumFields = [];

    public function handle()
    {
        $name = $this->argument('name');

        $modelPath = app_path("Models/{$name}.php");
        if (! File::exists($modelPath)) {
            $this->error("Model {$name} does not exist!");
            return;
        }

        $fields = $this->getFillableFields($name);
        if (empty($fields)) {
            $this->error("No fillable fields found in {$name} model!");
            return;
        }

        $this->createRequestFiles($name, $fields);
        $this->createController($name, $fields);
        $this->createViews($name, $fields);
        $this->updateRoutes($name);

        $this->info("CRUD for {$name} successfully generated!");
    }

    protected function getFillableFields($name)
    {
        $modelClass = "App\\Models\\{$name}";
        if (! class_exists($modelClass)) {
            return [];
        }

        $model = new $modelClass();
        return $model->getFillable();
    }

    protected function createRequestFiles($name, $fields)
    {
        $requestPath = app_path('Http/Requests');
        if (! File::exists($requestPath)) {
            File::makeDirectory($requestPath, 0755, true);
        }

        $modelClass    = "App\\Models\\{$name}";
        $modelInstance = new $modelClass();
        $tableName     = $modelInstance->getTable();

        $enumFields = property_exists($modelInstance, 'enumValues') ? $modelInstance->enumValues : [];

        $validationRules = '';
        foreach ($fields as $field) {
            $columnType = Schema::getColumnType($tableName, $field);
            $rule       = 'required';

            if (isset($enumFields[$field])) {
                $this->enumFields[$field] = [
                    'values'  => $enumFields[$field]['values'],
                    'default' => $enumFields[$field]['default'] ?? null,
                ];
                $rule .= "|in:" . implode(',', $enumFields[$field]['values']);
            } else {
                if (Str::endsWith($field, '_id')) {
                    $rule .= '|integer';
                } else {
                    switch ($columnType) {
                        case 'integer':
                        case 'bigint':
                        case 'smallint':
                        case 'tinyint':
                            $rule .= '|integer';
                            break;
                        case 'unsignedBigInteger':
                            $rule .= '|integer|min:0';
                            break;
                        case 'string':
                        case 'varchar':
                            $rule .= '|string|max:255';
                            if (Str::endsWith($field, 'email')) {
                                $rule .= '|email';
                            }
                            break;
                        case 'text':
                            $rule .= '|string';
                            break;
                        case 'decimal':
                        case 'float':
                        case 'double':
                            $rule .= '|numeric';
                            break;
                        case 'boolean':
                            $rule .= '|boolean';
                            break;
                        case 'date':
                        case 'datetime':
                        case 'timestamp':
                            $rule .= '|date';
                            break;
                        default:
                            $rule .= '|string|max:255';
                            break;
                    }
                }
            }

            $validationRules .= "            '{$field}' => '{$rule}',\n";
        }

        $storeRequestTemplate = <<<EOT
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class Store{$name}Request extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
{$validationRules}
        ];
    }
}
EOT;
        File::put(app_path("Http/Requests/Store{$name}Request.php"), $storeRequestTemplate);

        $updateRequestTemplate = <<<EOT
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class Update{$name}Request extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
{$validationRules}
        ];
    }
}
EOT;
        File::put(app_path("Http/Requests/Update{$name}Request.php"), $updateRequestTemplate);
    }

    protected function createController($name, $fields)
    {
        $pluralName  = Str::plural(strtolower($name));
        $searchLogic = '';
        foreach ($fields as $field) {
            $searchLogic .= "        if (\$request->filled('{$field}')) {\n";
            $searchLogic .= "            \$query->where('{$field}', 'like', '%' . \$request->input('{$field}') . '%');\n";
            $searchLogic .= "        }\n";
        }

        $controllerTemplate = <<<EOT
<?php

namespace App\Http\Controllers;

use App\Models\\{$name};
use App\Http\Requests\Store{$name}Request;
use App\Http\Requests\Update{$name}Request;
use Illuminate\Http\Request;

class {$name}Controller extends Controller
{
    public function index(Request \$request)
    {
        \$query = {$name}::query();
{$searchLogic}
        \$models = \$query->paginate(10);
        return view('{$pluralName}.index', ['models' => \$models]);
    }

    public function create()
    {
        return view('{$pluralName}.create');
    }

    public function store(Store{$name}Request \$request)
    {
        {$name}::create(\$request->validated());
        return redirect()->route('{$pluralName}.index')->with('success', '{$name} created successfully!');
    }

    public function show(\$id)
    {
        \$model = {$name}::findOrFail(\$id);
        return view('{$pluralName}.show', ['model' => \$model]);
    }

    public function edit(\$id)
    {
        \$model = {$name}::findOrFail(\$id);
        return view('{$pluralName}.edit', ['model' => \$model]);
    }

    public function update(Update{$name}Request \$request, \$id)
    {
        \$model = {$name}::findOrFail(\$id);
        \$model->update(\$request->validated());
        return redirect()->route('{$pluralName}.index')->with('success', '{$name} updated successfully!');
    }

    public function destroy(\$id)
    {
        \$model = {$name}::findOrFail(\$id);
        \$model->delete();
        return redirect()->route('{$pluralName}.index')->with('success', '{$name} deleted successfully!');
    }
}
EOT;
        File::put(app_path("Http/Controllers/{$name}Controller.php"), $controllerTemplate);
    }

    protected function createViews($name, $fields)
    {
        $pluralName = Str::plural(strtolower($name));
        File::makeDirectory(resource_path("views/{$pluralName}"), 0755, true, true);

        $enumFields = $this->enumFields ?? [];

        // index.blade.php
        $tableHeaders = "                        <th>#</th>\n";
        $searchInputs = "                        <th></th>\n";
        $tableRow     = '';
        foreach ($fields as $field) {
            $fieldFormatted = ucwords(str_replace('_', ' ', $field));
            $tableHeaders .= "                        <th>{$fieldFormatted}</th>\n";
            $searchInputs .= "                        <th><input type=\"text\" name=\"{$field}\" class=\"form-control\" placeholder=\"{$fieldFormatted}\" value=\"{{ request('{$field}') }}\"></th>\n";
            $tableRow .= "                    <td>{{ \$model->{$field} }}</td>\n";
        }

        $indexTemplate = <<<EOT
@extends('crud')

@section('title', '{$name}')

@section('content')
    <div class="container">
        <h1>{$name}</h1>
        @if (session('success'))
            <div class="alert alert-success" role="alert">
                {{ session('success') }}
            </div>
        @endif
        <a href="{{ route('{$pluralName}.create') }}" class="btn btn-primary mb-3">Create New {$name}</a>
        <table class="table table-bordered">
            <thead>
                <tr>
{$tableHeaders}
                    <th>Actions</th>
                </tr>
                <form method="GET" action="{{ route('{$pluralName}.index') }}">
                    <tr>
{$searchInputs}
                        <th><button type="submit" class="btn btn-secondary btn-sm">Search</button></th>
                    </tr>
                </form>
            </thead>
            <tbody>
                @foreach (\$models as \$model)
                <tr>
                    <td>{{ (\$models->currentPage() - 1) * \$models->perPage() + \$loop->iteration }}</td>
{$tableRow}
                    <td>
                        <a href="{{ route('{$pluralName}.show', \$model->id) }}" class="btn btn-info btn-sm">View</a>
                        <a href="{{ route('{$pluralName}.edit', \$model->id) }}" class="btn btn-warning btn-sm">Edit</a>
                        <form action="{{ route('{$pluralName}.destroy', \$model->id) }}" method="POST" style="display:inline;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure?')">Delete</button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        {{ \$models->links() }}
    </div>
@endsection
EOT;
        File::put(resource_path("views/{$pluralName}/index.blade.php"), $indexTemplate);

        // create.blade.php
        $formFields = '';
        foreach ($fields as $field) {
            $fieldFormatted = ucwords(str_replace('_', ' ', $field));
            if (isset($enumFields[$field])) {
                $options = '';
                foreach ($enumFields[$field]['values'] as $value) {
                    $selected = ($value === $enumFields[$field]['default']) ? ' selected' : '';
                    $options .= "<option value=\"{$value}\"{$selected}>{$value}</option>\n";
                }
                $formFields .= <<<EOT
            <div class="mb-3">
                <label for="{$field}" class="form-label">{$fieldFormatted}</label>
                <select name="{$field}" class="form-control" id="{$field}">
{$options}
                </select>
                @error('{$field}') <span class="text-danger">{{ \$message }}</span> @enderror
            </div>
EOT;
            } else {
                $formFields .= <<<EOT
            <div class="mb-3">
                <label for="{$field}" class="form-label">{$fieldFormatted}</label>
                <input type="text" name="{$field}" class="form-control" placeholder="{$fieldFormatted}" value="{{ old('{$field}') }}">
                @error('{$field}') <span class="text-danger">{{ \$message }}</span> @enderror
            </div>
EOT;
            }
        }

        $createTemplate = <<<EOT
@extends('crud')

@section('title', 'Create {$name}')

@section('content')
    <div class="container mt-5">
        <h1>Create New {$name}</h1>
        @if (session('success'))
            <div class="alert alert-success" role="alert">
                {{ session('success') }}
            </div>
        @endif
        <form method="POST" action="{{ route('{$pluralName}.store') }}">
            @csrf
{$formFields}
            <button type="submit" class="btn btn-success">Save</button>
            <a href="{{ route('{$pluralName}.index') }}" class="btn btn-secondary">Back</a>
        </form>
    </div>
@endsection
EOT;
        File::put(resource_path("views/{$pluralName}/create.blade.php"), $createTemplate);

        // edit.blade.php
        $editFormFields = '';
        foreach ($fields as $field) {
            $fieldFormatted = ucwords(str_replace('_', ' ', $field));
            if (isset($enumFields[$field])) {
                $options = '';
                foreach ($enumFields[$field]['values'] as $value) {
                    $options .= "<option value=\"{$value}\" {{ \$model->{$field} === '{$value}' ? 'selected' : '' }}>{$value}</option>\n";
                }
                $editFormFields .= <<<EOT
            <div class="mb-3">
                <label for="{$field}" class="form-label">{$fieldFormatted}</label>
                <select name="{$field}" class="form-control" id="{$field}">
{$options}
                </select>
                @error('{$field}') <span class="text-danger">{{ \$message }}</span> @enderror
            </div>
EOT;
            } else {
                $editFormFields .= <<<EOT
            <div class="mb-3">
                <label for="{$field}" class="form-label">{$fieldFormatted}</label>
                <input type="text" name="{$field}" class="form-control" placeholder="{$fieldFormatted}" value="{{ \$model->{$field} ?? '' }}">
                @error('{$field}') <span class="text-danger">{{ \$message }}</span> @enderror
            </div>
EOT;
            }
        }

        $editTemplate = <<<EOT
@extends('crud')

@section('title', 'Edit {$name}')

@section('content')
    <div class="container mt-5">
        <h1>Edit {$name}</h1>
        @if (session('success'))
            <div class="alert alert-success" role="alert">
                {{ session('success') }}
            </div>
        @endif
        <form method="POST" action="{{ route('{$pluralName}.update', \$model->id) }}">
            @csrf
            @method('PUT')
{$editFormFields}
            <button type="submit" class="btn btn-success">Update</button>
            <a href="{{ route('{$pluralName}.index') }}" class="btn btn-secondary">Back</a>
        </form>
    </div>
@endsection
EOT;
        File::put(resource_path("views/{$pluralName}/edit.blade.php"), $editTemplate);

        // show.blade.php
        $showFields = '';
        foreach ($fields as $field) {
            $fieldFormatted = ucwords(str_replace('_', ' ', $field));
            $showFields .= "<p><strong>{$fieldFormatted}:</strong> {{ \$model->{$field} }}</p>\n";
        }

        $showTemplate = <<<EOT
@extends('crud')

@section('title', 'Show {$name}')

@section('content')
    <div class="container mt-5">
        <h1>{$name} Details</h1>
        @if (session('success'))
            <div class="alert alert-success" role="alert">
                {{ session('success') }}
            </div>
        @endif
{$showFields}
        <a href="{{ route('{$pluralName}.edit', \$model->id) }}" class="btn btn-warning btn-sm">Edit</a>
        <a href="{{ route('{$pluralName}.index') }}" class="btn btn-secondary">Back</a>
    </div>
@endsection
EOT;
        File::put(resource_path("views/{$pluralName}/show.blade.php"), $showTemplate);
    }

    protected function updateRoutes($name)
    {
        $pluralName = Str::plural(strtolower($name));
        $routeEntry = "Route::resource('{$pluralName}', App\\Http\\Controllers\\{$name}Controller::class);\n";
        File::append(base_path('routes/web.php'), $routeEntry);
    }
}
