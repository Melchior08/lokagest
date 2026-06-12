<?php
class TCPDF {
    protected $html = "";
    protected $pageOrientation = "P";
    protected $pageSize = "A4";
    public function __construct($orientation = "P", $unit = "mm", $format = "A4", $unicode = true, $encoding = "UTF-8", $diskcache = false) {
        $this->pageOrientation = $orientation;
        $this->pageSize = $format;
    }
    public function SetCreator($creator) {}
    public function SetAuthor($author) {}
    public function SetTitle($title) {}
    public function SetSubject($subject) {}
    public function SetMargins($left, $top, $right = -1, $keepmargins = false) {}
    public function SetHeaderMargin($margin) {}
    public function SetFooterMargin($margin) {}
    public function SetAutoPageBreak($auto, $margin = 0) {}
    public function AddPage($orientation = "", $format = "", $keepmargins = false, $tocpage = false) {}
    public function writeHTML($html, $ln = true, $fill = false, $reseth = false, $cell = false, $align = "") {
        $this->html .= $html;
    }
    public function Output($name = "doc.pdf", $dest = "I") {
        // En cas de fallback, on envoie la version HTML stylisée prête pour l'impression
        if ($dest === "S") {
            return $this->html;
        }
        
        // CSS d'impression élégant pour simuler un document PDF
        echo "<html><head><title>Impression Document</title>";
        echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>";
        echo "<style>
            body { background: #f1f5f9; padding: 20px; font-family: sans-serif; }
            .page { background: white; width: 210mm; min-height: 297mm; padding: 20mm; margin: 0 auto; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-radius: 8px; }
            @media print {
                body { background: white; padding: 0; }
                .page { box-shadow: none; margin: 0; width: 100%; min-height: auto; padding: 0; }
                .no-print { display: none !important; }
            }
        </style></head><body>";
        echo "<div class='no-print text-center mb-4'><button class='btn btn-success rounded-pill px-4 fw-bold' onclick='window.print()'>🖨️ Imprimer / Sauvegarder en PDF</button></div>";
        echo "<div class='page'>" . $this->html . "</div>";
        echo "</body></html>";
        exit;
    }
}
