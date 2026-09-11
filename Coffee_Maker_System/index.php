<?php

session_start();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coffee Maker - Login</title>

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, Helvetica, sans-serif;
        }

        body {
            background: #f5f5f5;
            min-height: 100dvh;
            height: 100dvh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
        }

        /* Main Login Container */
        .login-container {
            width: 100vw;
            height: 100dvh;
            display: flex;
            background: white;
        }

        /* LEFT SIDE */
        .left-section {
            width: 38.5%;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .logo {
            width: 120px;
            height: auto;
            margin-bottom: 38px;
        }

        .coffee-title {
            font-size: 44px;
            font-weight: 400;
            color: #222;
            letter-spacing: -1px;
        }

        .title-line {
            width: 40px;
            height: 5px;
            background: #000;
            border-radius: 5px;
            margin-top: 25px;
        }

        /* RIGHT SIDE */
        .right-section {
            width: 61.5%;
            background: #916f65;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* LOGIN CARD */
        .login-card {
            width: 385px;
            height: 368px;
            background: white;
            border-radius: 8px;
            padding: 54px 28px;
        }

        .input-group {
            margin-bottom: 17px;
        }

        label {
            display: block;
            font-size: 8px;
            font-weight: bold;
            letter-spacing: 1px;
            color: #333;
            margin-bottom: 7px;
        }

        .input-box {
            position: relative;
        }

        .input-box input {
            width: 100%;
            height: 44px;
            border: 1px solid #bdbdbd;
            border-radius: 7px;
            padding: 0 35px;
            font-size: 12px;
            outline: none;
        }

        .input-box input:focus {
            border-color: #777;
        }

        .input-box input::placeholder {
            color: #aaa;
        }

        .icon {
            position: absolute;
            left: 10px;
            top: 13px;
            font-size: 13px;
            color: #888;
        }

        .eye {
            position: absolute;
            right: 10px;
            top: 13px;
            font-size: 13px;
            color: #999;
            cursor: pointer;
        }

        /* LOGIN BUTTON */
        .login-button {
            width: 100%;
            height: 46px;
            margin-top: 10px;
            border: none;
            border-radius: 6px;
            background: #000;
            color: white;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 1px;
            cursor: pointer;
        }

        .login-button:hover {
            background: #333;
        }

        .arrow {
            font-size: 17px;
            vertical-align: middle;
            margin-left: 3px;
        }

        /* Bottom line */
        .bottom-line {
            width: 100%;
            height: 1px;
            background: #eeeeee;
            margin-top: 20px;
        }

        .self-order-button {
            width: 100%;
            height: 38px;
            margin-top: 14px;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            background: #fff;
            color: #443630;
            font-size: 10px;
            cursor: pointer;
        }

        .self-order-button:hover {
            background: #f7f2ef;
            border-color: #cbbab2;
        }

        /* Error Message */
        .error-message {
            color: #b00020;
            font-size: 10px;
            margin-bottom: 12px;
            text-align: center;
        }

        /* RESPONSIVE */
        @media (max-width: 900px) {

            .login-container {
                width: 100%;
                height: min(600px, calc(100% - 32px));
            }

            .coffee-title {
                font-size: 30px;
            }
        }

        @media (max-width: 600px) {

            body {
                height: auto;
                min-height: 100vh;
                overflow-y: auto;
            }

            .login-container {
                width: 90%;
                height: auto;
                flex-direction: column;
            }

            .left-section,
            .right-section {
                width: 100%;
            }

            .left-section {
                height: 300px;
            }

            .right-section {
                height: 350px;
            }
        }

    </style>

</head>

<body>

    <div class="login-container">

        <!-- =========================
             LEFT SECTION
        ========================== -->

        <div class="left-section">

            <!-- Your Coffee Maker Logo -->
            <img 
                src="assets/images/logo.png"
                alt="Coffee Maker Logo"
                class="logo"
            >

            <h1 class="coffee-title">
                COFFEE MAKER
            </h1>

            <div class="title-line"></div>

        </div>


        <!-- =========================
             RIGHT SECTION
        ========================== -->

        <div class="right-section">

            <!-- LOGIN FORM -->
            <form 
                class="login-card"
                action="login_process.php"
                method="POST"
            >

                <?php

                // Display error if login_process.php sends one
                if (isset($_GET["error"])) {

                    echo '<div class="error-message">
                            Invalid Employee ID or Password.
                          </div>';

                }

                ?>


                <!-- =========================
                     USERNAME / EMPLOYEE ID
                ========================== -->

                <div class="input-group">

                    <label>
                        USERNAME / EMPLOYEE ID
                    </label>

                    <div class="input-box">

                        <span class="icon">
                            ♙
                        </span>

                        <input
                            type="text"
                            name="username"
                            placeholder="Enter ID"
                            autocomplete="username"
                            required
                        >

                    </div>

                </div>


                <!-- =========================
                     PASSWORD
                ========================== -->

                <div class="input-group">

                    <label>
                        PASSWORD
                    </label>

                    <div class="input-box">

                        <span class="icon">
                            🔒
                        </span>

                        <input
                            type="password"
                            name="password"
                            id="password"
                            placeholder="••••••••"
                            autocomplete="current-password"
                            required
                        >

                        <span
                            class="eye"
                            onclick="togglePassword()"
                        >
                            ◉
                        </span>

                    </div>

                </div>


                <!-- =========================
                     LOGIN BUTTON
                ========================== -->

                <button
                    type="submit"
                    class="login-button"
                >

                    LOGIN

                    <span class="arrow">
                        →
                    </span>

                </button>


                <div class="bottom-line"></div>

                <button
                    type="button"
                    class="self-order-button"
                    onclick="window.location.href='pages/self_order.php'"
                >
                    ♜ &nbsp; Self-Ordering
                </button>

            </form>

        </div>

    </div>


    <!-- =========================
         JAVASCRIPT
    ========================== -->

    <script>

        function togglePassword() {

            const password =
                document.getElementById("password");

            if (password.type === "password") {

                password.type = "text";

            } else {

                password.type = "password";

            }

        }

    </script>

</body>

</html>