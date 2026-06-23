<?php
$hash = '$2y$10$7vI/lPPqVobGF2bpDhP54eS4OOSr5eIBZyV3VCRN2IuGTn28SYUBu';
$password = '12345678';

if (password_verify($password, $hash)) {
    echo "Password MATCHED";
} else {
    echo "Password NOT MATCHED";
}