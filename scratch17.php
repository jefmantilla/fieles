<?php
$file = 'c:\xampp\htdocs\Aplicaiones\fieles\encuestadora\dashboard.php';
$content = file_get_contents($file);

// Find the misplaced section and move the endforeach/endif ABOVE it.
$misplacedPattern = <<<HTML
                                <?php endif; ?>

                    <!-- SELECCIÓN DE CANDIDATO O ESTADO DE LA LLAMADA -->
HTML;

$correctPattern = <<<HTML
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- SELECCIÓN DE CANDIDATO O ESTADO DE LA LLAMADA -->
HTML;

$content = str_replace($misplacedPattern, $correctPattern, $content);

// Now remove the leftover endforeach/endif at the bottom of the old block area, which was:
//                             </div>
//                         <?php endforeach; ? >
//                     <?php endif; ? >
// Let's find it after the estados de llamada.
// Wait, the block was previously before `id="seccionEstadoVotacion"`
$leftover = <<<HTML
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Pregunta Estado de Votación en Yopal -->
HTML;
$fixedLeftover = <<<HTML
                    <!-- Pregunta Estado de Votación en Yopal -->
HTML;

// Just to be safe, I'll use preg_replace for the leftover
$content = preg_replace('/<\/div>\s*<\?php endforeach; \?>\s*<\?php endif; \?>\s*<!-- Pregunta Estado de Votación en Yopal -->/s', '<!-- Pregunta Estado de Votación en Yopal -->', $content);

file_put_contents($file, $content);
echo "ok";
