<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\admin\usuarios.php';
$content = file_get_contents($file);

$content = str_replace('<th>Teléfono</th>', "<th>Teléfono</th>\n                                        <th>Rol</th>", $content);
$content = str_replace('<td><?= e($u[\'telefono\']) ?></td>', "<td><?= e(\$u['telefono']) ?></td>\n                                            <td><span class=\"badge bg-info\"><?= e(\$u['role_name']) ?></span></td>", $content);

file_put_contents($file, $content);
echo "ok";
