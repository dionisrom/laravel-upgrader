<?php

class StoreUserRequest extends FormRequest
{
    public function rules()
    {
        return ['name' => 'required|string'];
    }

    public function authorize()
    {
        return true;
    }
}
-----
<?php

class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required|string'];
    }

    public function authorize(): bool
    {
        return true;
    }
}
