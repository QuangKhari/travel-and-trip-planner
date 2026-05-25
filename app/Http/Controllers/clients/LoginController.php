<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\Login;

class LoginController extends Controller
{
    private $login;
    public function __construct(){
        $this->login = new Login();
    }
    public function index()
    {
        $title = 'Đăng nhập';
        return view('clients.login', compact('title'));
    }

    public function register(Request $request)
    {
        $username_regis = $request->username_regis;
        $email = $request->email;
        $password_regis = $request->password_regis;


        $checkAccountExist = $this->login->checkUserExist($username_regis, $email);

        if($checkAccountExist){
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản đã tồn tại'
            ]);
        }
        $dataInsert = [
                'username' => $username_regis,
                'email' => $email,
                'password' => md5($password_regis)
            ];
            $this->login->registerAccount($dataInsert);
            return response()->json([
                'success' => true,
                'message' => 'Đăng ký thành công'
            ]);
    }


    //xử lý người dùng đăng nhập
    public function login(Request $request){
        $username = $request->username;
    $password = $request->password;

    $data_login = [
        'username' => $username,
        'password' => md5($password)
    ];

    $user = $this->login->login($data_login);

    if($user){

        $request->session()->put('username', $username);

        return response()->json([
            'success' => true,
            'message' => 'Đăng nhập thành công',
            'redirectUrl' => route('home')
        ]);

    }else{

        return redirect()->route('login');


    }
    
}
    public function logout(Request $request)
    {
        $request->session()->forget('username');
        return redirect()->route('home');
    }
}