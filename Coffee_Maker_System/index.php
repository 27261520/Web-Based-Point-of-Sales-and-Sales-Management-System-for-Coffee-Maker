<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coffee Maker - Login</title>

    <!-- POS Font Import (Plus Jakarta Sans) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
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
            padding: 20px;
        }

        .logo {
            width: 120px;
            height: auto;
            margin-bottom: 28px;
        }

        .coffee-title {
            font-size: 32px;
            font-weight: 700;
            color: #2B1610;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .title-line {
            width: 40px;
            height: 4px;
            background: #2B1610;
            border-radius: 4px;
            margin-top: 20px;
        }

        /* RIGHT SIDE */
        .right-section {
            width: 61.5%;
            background: #2B1610;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* LOGIN CARD */
        .login-card {
            width: 385px;
            height: auto;
            background: #ffffff;
            border-radius: 12px;
            padding: 38px 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        .input-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #20242b;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .input-box {
            position: relative;
        }

        .input-box input {
            width: 100%;
            height: 44px;
            border: 1px solid #dcd6d3;
            border-radius: 7px;
            padding: 0 14px;
            font-size: 13px;
            font-weight: 500;
            color: #20242b;
            outline: none;
            transition: border-color 0.2s ease;
        }

        .input-box input.has-eye {
            padding-right: 38px;
        }

        .input-box input:focus {
            border-color: #74473b;
        }

        .input-box input::placeholder {
            color: #8f95a0;
            font-weight: 400;
        }

        /* EYE ICON STYLING */
        .eye {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 14px;
            color: #74473b;
            cursor: pointer;
            user-select: none;
            transition: color 0.2s ease;
        }

        .eye:hover {
            color: #2B1610;
        }

        /* LOGIN BUTTON */
        .login-button {
            width: 100%;
            height: 46px;
            margin-top: 10px;
            border: none;
            border-radius: 7px;
            background: #2B1610;
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.8px;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .login-button:hover {
            background: #74473b;
        }

        .arrow {
            font-size: 15px;
            vertical-align: middle;
            margin-left: 4px;
        }

        /* Bottom line */
        .bottom-line {
            width: 100%;
            height: 1px;
            background: #eeeeee;
            margin: 20px 0;
        }

        .self-order-button {
            width: 100%;
            height: 42px;
            border: 1px solid #dcd6d3;
            border-radius: 7px;
            background: #ffffff;
            color: #2B1610;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease, border-color 0.2s ease;
        }

        .self-order-button:hover {
            background: #f7f2ef;
            border-color: #74473b;
        }

        /* Error Message */
        .error-message {
            color: #d32f2f;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 16px;
            text-align: center;
        }

        /* RESPONSIVE */
        @media (max-width: 900px) {
            .login-container {
                width: 100%;
                height: min(600px, calc(100% - 32px));
            }

            .coffee-title {
                font-size: 26px;
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
                margin: 20px 0;
            }

            .left-section,
            .right-section {
                width: 100%;
            }

            .left-section {
                height: 250px;
            }

            .right-section {
                padding: 30px 20px;
            }

            .login-card {
                width: 100%;
            }
        }
    </style>
</head>

<body>

    <div class="login-container">

        <!-- LEFT SECTION -->
        <div class="left-section">
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

        <!-- RIGHT SECTION -->
        <div class="right-section">

            <form 
                class="login-card"
                action="login_process.php"
                method="POST"
            >

                <?php
                if (isset($_GET["error"])) {
                    echo '<div class="error-message">
                            Invalid Employee ID or Password.
                          </div>';
                }
                ?>

                <!-- USERNAME / EMPLOYEE ID -->
                <div class="input-group">
                    <label for="username">USERNAME</label>
                    <div class="input-box">
                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Enter Username"
                            autocomplete="username"
                            required
                        >
                    </div>
                </div>

                <!-- PASSWORD -->
                <div class="input-group">
                    <label for="password">PASSWORD</label>
                    <div class="input-box">
                        <input
                            type="password"
                            name="password"
                            id="password"
                            class="has-eye"
                            placeholder="Enter Password"
                            autocomplete="current-password"
                            required
                        >
                        <i
                            class="fas fa-eye eye"
                            id="toggleIcon"
                            onclick="togglePassword()"
                        ></i>
                    </div>
                </div>

                <!-- LOGIN BUTTON -->
                <button
                    type="submit"
                    class="login-button"
                >
                    LOGIN
                    <span class="arrow">→</span>
                </button>

                <div class="bottom-line"></div>

                <button
                    type="button"
                    class="self-order-button"
                    onclick="window.location.href='pages/self_order.php'"
                >
                    Self-Ordering
                </button>

            </form>

        </div>

    </div>

    <!-- JAVASCRIPT -->
    <script>
        function togglePassword() {
            const password = document.getElementById("password");
            const toggleIcon = document.getElementById("toggleIcon");

            if (password.type === "password") {
                password.type = "text";
                toggleIcon.classList.remove("fa-eye");
                toggleIcon.classList.add("fa-eye-slash");
            } else {
                password.type = "password";
                toggleIcon.classList.remove("fa-eye-slash");
                toggleIcon.classList.add("fa-eye");
            }
        }
    </script>

</body>

</html>