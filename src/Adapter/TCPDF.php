<?php

/**
 * Based on:
 * @package dompdf
 * @link    http://www.dompdf.com/
 * @author  Benj Carson <benjcarson@digitaljunkies.ca>
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 * @version $Id: tcpdf_adapter.cls.php 448 2011-11-13 13:00:03Z fabien.menager $
 */

namespace Dompdf\Adapter;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\Exception;
use Dompdf\FontMetrics;
use Dompdf\Helpers;
use Dompdf\Image\Cache;

/**
 * TCPDF PDF Rendering interface
 *
 * TCPDF_Adapter provides a simple, stateless interface to TCPDF.
 *
 * Unless otherwise mentioned, all dimensions are in points (1/72 in).
 * The coordinate origin is in the top left corner and y values
 * increase downwards.
 *
 * See {@link http://tcpdf.sourceforge.net} for more information on
 * the underlying TCPDF class.
 *
 * @package dompdf
 */
class TCPDF implements Canvas
{
    /**
     * Dimensions of paper sizes in points
     *
     * @var array;
     */
    public static $PAPER_SIZES = []; // Set to Dompdf\Adapter\CPDF::$PAPER_SIZES below

    /**
     * The Dompdf object
     *
     * @var Dompdf
     */
    protected $_dompdf;

    /**
     * Fudge factor to adjust reported font heights
     *
     * CPDF reports larger font heights than TCPDF. This factor
     * adjusts the height reported by get_font_height().
     *
     * CORRECTION: at a certain point, with given DOMPDF and TCPDF versions, this was true
     * and the number was 1.116. Now it does not seem necessary anymore.
     * Just in case it will be useful again in the future, I leave it in the code setting it to 1
     *
     * @var float
     */
    const FONT_HEIGHT_SCALE_NORMAL = 1.116;
    const FONT_HEIGHT_SCALE_BOLD = 1.153;

    /**
     * Instance of the TCPDF class
     *
     * @var TCPDF
     */
    private $_pdf;

    /**
     * PDF width in points
     *
     * @var float
     */
    private $_width;

    /**
     * PDF height in points
     *
     * @var float
     */
    private $_height;

    /**
     * Last fill colour used
     *
     * @var array
     */
    private $_last_fill_color;

    /**
     * Last stroke colour used
     *
     * @var array
     */
    private $_last_stroke_color;

    /**
     * Cache of image handles
     *
     * @var array
     */
    private $_imgs;

    /**
     * Cache of font handles
     *
     * @var array
     */
    private $_fonts;

    /**
     * List of objects (templates) to add to multiple pages
     *
     * @var array
     */
    private $_objs;

    /**
     * Array of pages for accesing after rendering is initially complete
     *
     * @var array
     */
    private $_pages;

    /**
     * Currently-applied opacity level (0 - 1)
     *
     * @var float
     */
    protected $_current_opacity = 1;

    /**
     * Array of temporary cached images to be deleted when processing is complete
     *
     * @var array
     */
    private $_image_cache;

    /**
     * Map named links (internal) to links ID
     */
    private $_nameddest;

    /**
     * Saves internal links info for later insertion
     */
    private $_internal_links;

    private $_currentLineTransparency; // not used because line transparency is set by fill transparency

    private $_currentFillTransparency; // array

    /**
     * Class constructor
     *
     * @param mixed $paper The size of paper to use either a string (see {@link CPDF_Adapter::$PAPER_SIZES})
     * @param string $orientation The orientation of the document (either "landscape" or "portrait")
     * @param DOMPDF $dompdf
     */
    public function __construct($paper = "letter", $orientation = "portrait", ?Dompdf $dompdf = null)
    {
        if (is_array($paper)) {
            $size = array_map("floatval", $paper);
        } else {
            $paper = strtolower($paper);
            $size = self::$PAPER_SIZES[$paper] ?? self::$PAPER_SIZES["letter"];
        }
        $ori = "P";
        if (mb_strtolower($orientation ?? "") === "landscape") {
            list($size[2], $size[3]) = [$size[3], $size[2]];
            $ori = "L";
        }
        $this->_width = $size[2] - $size[0];
        $this->_height = $size[3] - $size[1];
        $this->_dompdf = $dompdf;
        $this->_pdf = new My_TCPDF("P", "pt", $paper, true, "UTF-8", false);
        $this->_pdf = new My_TCPDF($ori, "pt", $paper, true, "UTF-8", false);
        $this->_pdf->setCreator("DOMPDF Converter");
        // CreationDate and ModDate info are added by TCPDF itself
        // don't use TCPDF page defaults
        $this->_pdf->setAutoPageBreak(false);
        $this->_pdf->setMargins(0, 0, 0, true);
        $this->_pdf->setPrintHeader(false); // remove default header/footer
        $this->_pdf->setPrintFooter(false);
        $this->_pdf->setHeaderMargin(0);
        $this->_pdf->setFooterMargin(0);
        $this->_pdf->setCellPadding(0);
        $this->_pdf->AddPage();
        $this->_pdf->setDisplayMode("fullpage", "continuous");
        $this->_page_number = $this->_page_count = 1;
        $this->_pages = [$this->_pdf->PageNo()];
        $this->_image_cache = [];
        // other TCPDF stuff
        $this->_objs = []; // for templating support
        $this->_nameddest = []; // for internal link support
        $this->_internal_links = []; // for internal link support
        $this->_pdf->setAlpha(1.0);
        $this->_currentLineTransparency = ["mode" => "Normal", "opacity" => 1.0];
        $this->_currentFillTransparency = ["mode" => "Normal", "opacity" => 1.0];
        $this->_last_fill_color = $this->_last_stroke_color = null;
    }

    /**
     * Get Dompdf
     *
     * @return Dompdf
     */
    public function get_dompdf()
    {
        return $this->_dompdf;
    }

    /**
     * Class destructor
     *
     * Deletes all temporary image files
     */
    public function __destruct()
    {
        foreach ($this->_image_cache as $img) {
            if (file_exists($img)) {
                @unlink($img);
            }
        }
    }

    /**
     * Returns the Tcpdf instance
     *
     * @return Tcpdf
     */
    public function get_lib_obj()
    {
        return $this->_pdf;
    }

    /**
     * Add meta information to the PDF
     *
     * TCPDF does not have a generic method to do this, but limits the possible info to add
     * to well specific cases
     *
     * @param string $label label of the value (Creator, Producter, etc.)
     * @param string $value the text to set
     */
    public function add_info(string $label, string $value): void
    {
        global $_dompdf_warnings;
        switch ($label) {
            case "Creator":
                $this->_pdf->setCreator($value);
                break;
            case "Author":
                $this->_pdf->setAuthor($value);
                break;
            case "Title":
                $this->_pdf->setTitle($value);
                break;
            case "Subject":
                $this->_pdf->setSubject($value);
                break;
            case "Keywords":
                $this->_pdf->setKeywords($value);
                break;
            default:
                $_dompdf_warnings[] = "add_info: label '$label' is not supported by the TCPDF library.";
                break;
        }
    }

    /**
     * Determines if the font supports the given character
     *
     * @param string $font The font file to use
     * @param string $char The character to check
     *
     * @return bool
     */
    public function font_supports_char(string $font, string $char): bool
    {
        return true; // Not implemented
    }

    /**
     * Opens a new "object" (template in PDFLib-speak)
     *
     * While an object is open, all drawing actions are recored in the object,
     * as opposed to being drawn on the current page. Objects can be added
     * later to a specific page or to several pages.
     *
     * The return value is an integer ID for the new object.
     *
     * @see TCPDF_Adapter::close_object()
     * @see TCPDF_Adapter::add_object()
     *
     * @return int
     */
    public function open_object()
    {
        /* TCPDF does not appear to have template support. Options:
         * 1) add template support to TCPDF
         * 2) throw an error
         * 3) implement the template support in this adapter
         *
         * I have chosen a mix of 1st and 3rd options, using code from the PDFLIB and CPDF adapters, and the CPDF library,
         * to make a TCPDF subclass
         * The only tests performed are those in the dompdf/www/test directory.
         *
         * What it does essentially is saving the TCPDF output buffer in a local stack, and resetting it to its initial value (empty string).
         * When the template is closed, the output collected from its opening, is moved to an internal template dictionary,
         * and the TCPDF output buffer is restored to the saved values (from the stack).
         *
         * The TCPDF output buffer cannot be accessed because it is private, and its accessor methods are protected.
         * Therefore, I used a derived class (My_TCPDF - see bottom of this file)
         *
         * To prevent any side effects to the TCPDF object state, while a template is open, I issue a rollbackTransaction() when the template is closed,
         * to restore everything to the original state. I don't know yet if this is necessary, correct, useful, etc. It needs further learning and testing.
         * It also makes the use of the stack pretty redundant. For the moment I keep both of them.
         */
        $this->_pdf->startTransaction();
        $ret = $this->_pdf->openObject();
        $this->_objs[$ret] = ["start_page" => $this->_pdf->PageNo()];
        return $ret;
    }

    /**
     * Reopen an existing object
     *
     * @param int $object the ID of a previously opened object
     */
    public function reopen_object($object)
    {
        $this->_pdf->startTransaction();
        $this->_pdf->reopenObject($object);
    }

    /**
     * Close the current object
     *
     * @see TCPDF_Adapter::open_object()
     */
    public function close_object()
    {
        $this->_pdf->closeObject();
        $this->_pdf->rollbackTransaction(true);
    }

    /**
     * Adds the specified object to the document
     *
     * $where can be one of:
     * - "add" add to current page only
     * - "all" add to every page from the current one onwards
     * - "odd" add to all odd numbered pages from now on
     * - "even" add to all even numbered pages from now on
     * - "next" add the object to the next page only
     * - "nextodd" add to all odd numbered pages from the next one
     * - "nexteven" add to all even numbered pages from the next one
     *
     * @param int $object the object handle returned by open_object()
     * @param string $where
     */
    public function add_object($object, $where = "all")
    {
        if (mb_strpos($where, "next") !== false) {
            $this->_objs[$object]["start_page"]++;
            $where = str_replace("next", "", $where);
            if ($where == "") {
                $where = "add";
            }
        }
        $this->_objs[$object]["where"] = $where;
    }

    /**
     * Stops the specified object from appearing in the document.
     *
     * The object will stop being displayed on the page following the
     * current one.
     *
     * @param int $object
     */
    public function stop_object($object)
    {
        if (!isset($this->_objs[$object])) {
            return;
        }
        $start = $this->_objs[$object]["start_page"];
        $where = $this->_objs[$object]["where"];
        // Place the object on this page if required
        $page_number = $this->_pdf->PageNo();
        if (
            $page_number >= $start &&
            (($page_number % 2 == 0 && $where == "even") ||
            ($page_number % 2 == 1 && $where == "odd") ||
            ($where == "all"))
        ) {
            $data = $this->_pdf->getObject($object);
            $this->_pdf->setPageBuffer($page_number, $data["c"], true);
        }
        unset($this->_objs[$object]);
    }

    /**
     * Add all active objects to the current page
     */
    protected function _place_objects()
    {
        foreach ($this->_objs as $obj => $props) {
            $start = $props["start_page"];
            $where = $props["where"];
            // Place the object on this page if required
            $page_number = $this->_pdf->PageNo();
            if (
                $page_number >= $start &&
                (($page_number % 2 == 0 && $where == "even") ||
                ($page_number % 2 == 1 && $where == "odd") ||
                ($where == "all"))
            ) {
                $data = $this->_pdf->getObject($obj);
                $this->_pdf->setPageBuffer($page_number, $data["c"], true);
            }
        }
    }

    /**
     * Sets line transparency
     * @see Tcpdf::setAlpha()
     *
     * In TCPDF the setAlpha() method, sets both line transparency and fill transparency
     *
     * Valid blend modes are (case-sensitive):
     *
     * Normal, Multiply, Screen, Overlay, Darken, Lighten,
     * ColorDodge, ColorBurn, HardLight, SoftLight, Difference,
     * Exclusion
     *
     * @param string $mode the blending mode to use
     * @param float $opacity 0.0 fully transparent, 1.0 fully opaque
     */
    protected function _set_line_transparency($mode, $opacity)
    {
        $this->_pdf->setAlpha($opacity, $mode);
        $this->_currentFillTransparency["opacity"] = $opacity;
        $this->_currentFillTransparency["mode"] = $mode;
    }

    /**
     * Sets fill transparency
     * @see Tcpdf::setAlpha()
     *
     * In TCPDF the setAlpha() method, sets both line transparency and fill transparency
     *
     * Valid blend modes are (case-sensitive):
     *
     * Normal, Multiply, Screen, Overlay, Darken, Lighten,
     * ColorDogde, ColorBurn, HardLight, SoftLight, Difference,
     * Exclusion
     *
     * @param string $mode the blending mode to use
     * @param float $opacity 0.0 fully transparent, 1.0 fully opaque
     */
    protected function _set_fill_transparency($mode, $opacity)
    {
        // Only create a new graphics state if required
        //if ( $mode != $this->_currentFillTransparency["mode"]  ||
        //$opacity != $this->_currentFillTransparency["opacity"] ) {
        $this->_pdf->setAlpha($opacity, $mode);
        $this->_currentFillTransparency["opacity"] = $opacity;
        $this->_currentFillTransparency["mode"] = $mode;
        //}
    }

    /**
     * Get RGB
     */
    protected function _get_rgb($color)
    {
        return [round(255 * $color[0]), round(255 * $color[1]), round(255 * $color[2])];
    }

    /**
     * Make line style
     */
    protected function _make_line_style($color = "", $width = "", $cap = "", $join = "", $dash = "")
    {
        $style = [];
        if ($color) {
            $style["color"] = $this->_get_rgb($color);
        }
        if ($width) {
            $style["width"] = $width;
        }
        if ($cap) {
            $style["cap"] = $cap;
        }
        if ($join) {
            $style["join"] = $join;
        }
        $style["dash"] = $dash ? implode(",", $dash) : 0;
        return $style;
    }

    /**
     * Add internal links
     */
    protected function _add_internal_links()
    {
        if (!count($this->_internal_links)) {
            return;
        }
        foreach ($this->_internal_links as $link) {
            extract($link); // compact("page", "name", "x", "y", "width", "height")
            $this->_pdf->setPage($page);
            $this->_pdf->Link($x, $y, $width, $height, $this->_nameddest[$name]);
        }
    }

    /**
     * Get font
     *
     * @param string $fontname Font family name
     * @param string $subtype Font subtype which can be one of 'normal', 'bold', 'italic' or 'bold_italic'
     * @param float $size Font size
     * @return string Font family + Font style (or Style::_get_font_family() will throw exception)
     */
    public function get_font($fontname, $subtype = "", $size = null)
    {
        $fontname = strtolower($fontname);
        $subtype = strtolower($subtype);
        if (preg_match('/([bi]+)$/', $fontname, $m)) {
            $fontname = preg_replace('/([bi]+)$/', "", $fontname);
            $subtype = $m[1];
        }
        switch ($subtype) {
            case "bold":
            case "b":
                $subtype = "b";
                break;
            case "italic":
            case "i":
                $subtype = "i";
                break;
            case "bold_italic":
            case "bi":
            case "ib":
                $subtype = "bi";
                break;
            default:
                $subtype = "";
        }
        $this->_pdf->setFont($fontname, $subtype, $size);
        return $this->_pdf->getFontFamily() . $this->_pdf->getFontStyle();
    }

    /******************************************************************************
     ***                 Interface Canvas implementation                        ***
     ******************************************************************************/
    /**
     * Get width
     *
     * @return float
     */
    public function get_width()
    {
        return $this->_width;
    }

    /**
     * Get height
     *
     * @return float
     */
    public function get_height()
    {
        return $this->_height;
    }

    /**
     * Returns the current page number
     *
     * @return int
     */
    public function get_page_number()
    {
        return $this->_pdf->PageNo();
    }

    /**
     * Sets the current page number
     *
     * @param int $num
     */
    public function set_page_number($num)
    {
        $this->_pdf->setPage($num);
    }

    /**
     * Returns the total number of pages
     *
     * @return int
     */
    public function get_page_count()
    {
        return $this->_pdf->getNumPages();
    }

    /**
     * Sets the total number of pages
     *
     * @param int $count
     */
    public function set_page_count($count)
    {
        $_dompdf_warnings[] = "TCPDF does not support setting the page count.";
    }

    /**
	 * Set line style
	 * @param array $style Line style. Array with keys among the following:
	 * <ul>
	 *	 <li>width (float): Width of the line in user units.</li>
	 *	 <li>cap (string): Type of cap to put on the line. Possible values are:
	 * butt, round, square. The difference between "square" and "butt" is that
	 * "square" projects a flat end past the end of the line.</li>
	 *	 <li>join (string): Type of join. Possible values are: miter, round,
	 * bevel.</li>
	 *	 <li>dash (mixed): Dash pattern. Is 0 (without dash) or string with
	 * series of length values, which are the lengths of the on and off dashes.
	 * For example: "2" represents 2 on, 2 off, 2 on, 2 off, ...; "2,1" is 2 on,
	 * 1 off, 2 on, 1 off, ...</li>
	 *	 <li>phase (integer): Modifier on the dash pattern which is used to shift
	 * the point at which the pattern starts.</li>
	 *	 <li>color (array): Draw color. Format: array(GREY) or array(R,G,B) or array(C,M,Y,K) or array(C,M,Y,K,SpotColorName).</li>
	 * </ul>
	 */
    protected function _set_line_style($width, $cap, $join, $dash)
    {
        $this->_pdf->setLineStyle(["width" => $width, "cap" => $cap, "join" => $join, "dash" => is_array($dash) ? implode(",", $dash) : 0]);
    }

    /**
     * Draws a line from x1,y1 to x2,y2
     */
    public function line($x1, $y1, $x2, $y2, $color, $width, $style = [], $cap = "butt")
    {
        $this->_set_line_style($width, $cap, "", $style);
        $this->_set_line_transparency("Normal", $this->_current_opacity);
        $this->_pdf->Line($x1, $y1, $x2, $y2, $this->_make_line_style($color, $width, "butt", "", $style));
    }

    /**
     * Draws an arc
     */
    public function arc($x, $y, $r1, $r2, $astart, $aend, $color, $width, $style = [], $cap = "butt")
    {
        $this->_set_line_style($width, $cap, "", $style);
        $this->_pdf->Ellipse($x, $this->y($y), $r1, $r2, 0, 8, $astart, $aend, false, false, true, false);
        $this->_set_line_transparency("Normal", $this->_current_opacity);
    }

    /**
     * Draws a rectangle at x1,y1 with width w and height h
     */
    public function rectangle($x1, $y1, $w, $h, $color, $width, $style = [], $cap = "butt")
    {
        $this->_set_line_style($width, $cap, "", $style);
        $this->_set_line_transparency("Normal", $this->_current_opacity);
        $this->_pdf->Rect($x1, $y1, $w, $h, "D", $this->_make_line_style($color, $width, "square", "miter", $style));
    }

    /**
     * Draws a filled rectangle at x1,y1 with width w and height h
     */
    public function filled_rectangle($x1, $y1, $w, $h, $color)
    {
        $this->_set_line_transparency("Normal", $this->_current_opacity);
        $this->_set_fill_transparency("Normal", $this->_current_opacity);
        if (isset($color["alpha"])) {
            $this->_pdf->setAlpha($color["alpha"]);
        }
        $this->_pdf->Rect($x1, $y1, $w, $h, "F", $this->_make_line_style($color, 1, "square", "miter", []), $this->_get_rgb($color));
    }

    /**
     * Starts a clipping rectangle at x1,y1 with width w and height h
     */
    public function clipping_rectangle($x1, $y1, $w, $h)
    {
        // Not implemented
    }

    /**
     * Starts a clipping round rectangle
     */
    public function clipping_roundrectangle($x1, $y1, $w, $h, $rTL, $rTR, $rBR, $rBL)
    {
        // Not implemented
    }

    /**
     * clipping polygon
     */
    public function clipping_polygon(array $points): void
    {
        // Not implemented
    }

    /**
     * Ends the last clipping shape
     */
    public function clipping_end()
    {
        // Not implemented
    }

    /**
     * Save current state
     */
    public function save()
    {
        // Not implemented
    }

    /**
     * Restore last state
     */
    public function restore()
    {
        // Not implemented
    }

    /**
     * Rotate
     */
    public function rotate($angle, $x, $y)
    {
        // Not implemented
    }

    /**
     * Skew
     */
    public function skew($angle_x, $angle_y, $x, $y)
    {
        // Not implemented
    }

    /**
     * Scale
     */
    public function scale($s_x, $s_y, $x, $y)
    {
        // Not implemented
    }

    /**
     * Translate
     */
    public function translate($t_x, $t_y)
    {
        // Not implemented
    }

    /**
     * Transform
     */
    public function transform($a, $b, $c, $d, $e, $f)
    {
        // Not implemented
    }

    /**
     * Draws a polygon
     *
     * The polygon is formed by joining all the points stored in the $points array.
     * $points has the following structure:
     * <code>
     * array(0 => x1,
     *       1 => y1,
     *       2 => x2,
     *       3 => y2,
     *       ...
     *       );
     * </code>
     *
     * See {@link Style::munge_color()} for the format of the color array.
     * See {@link Cpdf::setLineStyle()} for a description of the $style
     * parameter (aka dash)
     *
     * @param array $points
     * @param array $color
     * @param float $width
     * @param array $style
     * @param bool  $fill Fills the polygon if true
     */
    public function polygon($points, $color, $width = null, $style = [], $fill = false, $blend = "Normal", $opacity = 1.0)
    {
        if (!$fill && isset($width)) {
            $this->_set_line_style($width, "square", "miter", $style);
        }
        $this->_set_line_transparency($blend, $opacity);
        if ($fill) {
            $this->_set_fill_transparency($blend, $opacity);
        }
        $this->_pdf->Polygon($points, $fill ? "F" : "", $this->_make_line_style($color, $width, "square", "miter", $style), $this->_get_rgb($color));
    }

    /**
     * Draws a circle at $x,$y with radius $r
     *
     * See {@link Style::munge_color()} for the format of the color array.
     * See {@link Cpdf::setLineStyle()} for a description of the $style
     * parameter (aka dash)
     *
     * @param float $x
     * @param float $y
     * @param float $r
     * @param array $color
     * @param float $width
     * @param array $style
     * @param bool $fill Fills the circle if true
     */
    public function circle($x, $y, $r, $color, $width = null, $style = [], $fill = false, $blend = "Normal", $opacity = 1.0)
    {
        if (!$fill && isset($width)) {
            $this->_set_line_style($width, "round", "round", $style);
        }
        $this->_set_line_transparency($blend, $opacity);
        if ($fill) {
            $this->_set_fill_transparency($blend, $opacity);
        }
        $this->_pdf->Circle($x, $y, $r, 0, 360, $fill ? "F" : "", $this->_make_line_style($color, $width, "round", "round", $style), $this->_get_rgb($color));
    }

    /**
     * Add an image to the pdf
     */
    public function image($img, $x, $y, $w, $h, $resolution = "normal")
    {
        [$width, $height, $type] = Helpers::dompdf_getimagesize($img, $this->get_dompdf()->getHttpContext());
        @$this->_pdf->Image($img, $x, $y, $w, $h, $type);
    }

    /**
     * Writes text at the specified x and y coordinates
     */
    public function text($x, $y, $text, $font, $size, $color = [0, 0, 0], $adjust = 0.0, $char_space = 0.0, $angle = 0.0, $blend = "Normal", $opacity = 1.0)
    {
        list($r, $g, $b) = $this->_get_rgb($color);
        $this->_pdf->setTextColor($r, $g, $b);
        $this->_set_line_transparency($blend, $opacity);
        $this->_set_fill_transparency($blend, $opacity);
        $this->get_font($font, "", $size);
        if ($adjust > 0) {
            $a = explode(" ", $text);
            $this->_pdf->setXY($x, $y);
            for ($i = 0; $i < count($a) - 1; $i++) {
                $this->_pdf->Write($size, $a[$i] . " ", "");
                //$this->_pdf->Text($x, $y, $a[$i]." ");
                $this->_pdf->setX($this->_pdf->GetX() + $adjust);
                //$x += $this->_pdf->GetX() + $adjust;
            }
            $this->_pdf->Write($size, $a[$i], "");
            //$this->_pdf->Text($x, $y, $a[$i]." ");
        } else {
            if ($angle != 0) {
                $this->_pdf->StartTransform();
                $this->_pdf->Rotate(-$angle, $x, $y);
                $this->_pdf->Text($x, $y, $text, false, false, true, 0, 0, "", 0, "", 0, false, "T", "T");
                $this->_pdf->StopTransform();
            } else {
                //$pippo = $this->_pdf->getFontAscent($fontdata["family"], $fontdata["style"], $size);
                //$y += $pippo / 8;
                //$y = $y - 0.85 * $size; // + 0.8 * $size;
                $this->_pdf->Text($x, $y, $text, false, false, true, 0, 0, "", 0, "", 0, false, "T", "T");
            }
        }
    }

    /**
     * Add a named destination (similar to <a name="foo">...</a> in html)
     *
     * @param string $anchorname The name of the named destination
     */
    public function add_named_dest($anchorname)
    {
        $link = $this->_pdf->AddLink();
        $this->_pdf->setLink($link, -1, -1);
        $this->_nameddest[$anchorname] = $link;
    }

    /**
     * Add a link to the pdf
     *
     * @param string $url The url to link to
     * @param float $x The x position of the link
     * @param float $y The y position of the link
     * @param float $width The width of the link
     * @param float $height The height of the link
     */
    public function add_link($url, $x, $y, $width, $height)
    {
        if (strpos($url, "#") === 0) {
            // Local link
            $name = substr($url, 1);
            if ($name) {
                $page = $this->_pdf->PageNo();
                //$this->_pdf->Link($x, $y, $width, $height, $this->_nameddest[$name]);
                $this->_internal_links[] = compact("page", "name", "x", "y", "width", "height"); //array($this->_pdf->PageNo(), $name, $x, $y, $width, $height);
            }
        } else {
            $this->_pdf->Link($x, $y, $width, $height, rawurldecode($url));
        }
    }

    /**
     * Calculates text size, in points
     *
     * @param string $text the text to be sized
     * @param string $font the desired font
     * @param float $size the desired font size
     * @param float $spacing word spacing, if any
     * @return float
     */
    public function get_text_width($text, $font, $size, $word_spacing = 0, $char_spacing = 0)
    {
        // remove non-printable characters since they have no width
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text); //***
        // Determine the additional width due to extra spacing
        $num_spaces = mb_substr_count($text, " ");
        $delta = $word_spacing * $num_spaces;
        $this->get_font($font, "", $size);
        $fontFamily = $this->_pdf->getFontFamily();
        $fontStyle = $this->_pdf->getFontStyle();
        return $this->_pdf->GetStringWidth($text, $fontFamily, $fontStyle, $size) + $delta;
    }

    /**
     * Calculates font height, in points
     *
     * TCPDF lacks a method to get the font height
     *
     * @param string $font
     * @param float $size
     * @return float
     */
    public function get_font_height($font, $size)
    {
        if (!$font) {
            $fontStyle = $this->_pdf->getFontStyle();
        } else {
            $this->get_font($font, "", $size);
            $fontStyle = $this->_pdf->getFontStyle();
        }
        if (strpos($fontStyle, "B") !== false) {
            $scale = self::FONT_HEIGHT_SCALE_BOLD;
        } else {
            $scale = self::FONT_HEIGHT_SCALE_NORMAL;
        }
        return $scale * $size;
    }

    /**
     * Get font baseline
     */
    public function get_font_baseline($font, $size)
    {
        $ratio = $this->_dompdf->getOptions()->getFontHeightRatio();
        return $this->get_font_height($font, $size) / $ratio;
    }

    /**
     * Set opacity
     */
    public function set_opacity(float $opacity, string $mode = "Normal"): void
    {
        $this->_set_line_transparency($mode, $opacity);
        $this->_set_fill_transparency($mode, $opacity);
        $this->_current_opacity = $opacity;
    }

    /**
     * Set default view
     */
    public function set_default_view($view, $options = [])
    {
        array_unshift($options, $view);
        $currentPage = $this->_pdf->currentPage;
        call_user_func_array([$this->_pdf, "openHere"], $options);
    }

    /**
     * Starts a new page
     *
     * Subsequent drawing operations will appear on the new page.
     */
    function new_page()
    {
        // Add objects to the current page
        $this->_place_objects();
        //$this->_pdf->endPage();
        $this->_pdf->lastPage();
        $this->_pdf->AddPage();
        //$this->_page_count++;
        $this->_pages[] = $this->_pdf->PageNo();
        return $this->_pdf->getNumPages();
    }

    /**
     * Streams the PDF directly to the browser
     *
     * @param string $filename the name of the PDF file
     * @param array $options associative array, "Attachment" => 0 or 1, "compress" => 1 or 0
     */
    public function stream($filename, $options = null)
    {
        // Add page text
        $this->_add_internal_links();
        // TCPDF expects file name with extension (cf. Cpdf expects file name without extension)
        if (!preg_match("/\.pdf$/", $filename)) {
            $filename .= ".pdf";
        }
        $options["Content-Disposition"] = $filename;
        if (isset($options["compress"]) && $options["compress"] == 0) {
            $compress = false;
        } else {
            $compress = true;
        }
        $this->_pdf->setCompression($compress);
        $this->_pdf->Output($filename, $options["Attachment"] ? "D" : "I");
    }

    /**
     * Returns the PDF as a string
     *
     * @param array $options associative array: "compress" => 1 or 0
     * @return string
     */
    public function output($options = null)
    {
        $this->_add_internal_links();
        $this->_place_objects();
        if (isset($options["compress"]) && $options["compress"] == 0) {
            $compress = false;
        } else {
            $compress = true;
        }
        $this->_pdf->setCompression($compress);
        return $this->_pdf->Output("", "S");
    }

    /**
     * Add JavaScript
     */
    public function javascript($code)
    {
        // Not implemented
    }

    /**
     * Other public methods callable from user pages
     */

    /**
     * Writes text at the specified x and y coordinates on every page
     *
     * The strings "{PAGE_NUM}" and "{PAGE_COUNT}" are automatically replaced
     * with their current values.
     *
     * See {@link Style::munge_colour()} for the format of the colour array.
     *
     * @param float $x
     * @param float $y
     * @param string $text the text to write
     * @param string $font the font file to use
     * @param float $size the font size, in points
     * @param array $color
     * @param float $word_space word spacing adjustment
     * @param float $char_space char spacing adjustment
     * @param float $angle angle to write the text at, measured CW starting from the x-axis
     */
    public function page_text($x, $y, $text, $font, $size, $color = [0, 0, 0], $word_space = 0.0, $char_space = 0.0, $angle = 0)
    {
        $this->processPageScript(function (int $pageNumber, int $pageCount) use ($x, $y, $text, $font, $size, $color, $word_space, $char_space, $angle) {
            $text = str_replace(
                ["{PAGE_NUM}", "{PAGE_COUNT}"],
                [$pageNumber, $pageCount],
                $text
            );
            $this->text($x, $y, $text, $font, $size, $color, $word_space, $char_space, $angle);
        });
    }

    public function page_line($x1, $y1, $x2, $y2, $color, $width, $style = [])
    {
        $this->processPageScript(function () use ($x1, $y1, $x2, $y2, $color, $width, $style) {
            $this->line($x1, $y1, $x2, $y2, $color, $width, $style);
        });
    }

    /**
     * Processes a callback or script on every page
     *
     * @param callable|string $callback The callback function or PHP script to process on every page
     */
    public function page_script($callback): void
    {
        if (is_string($callback)) {
            $this->processPageScript(function (
                int $PAGE_NUM,
                int $PAGE_COUNT,
                self $pdf,
                FontMetrics $fontMetrics
            ) use ($callback) {
                eval($callback);
            });
            return;
        }

        $this->processPageScript($callback);
    }

    protected function processPageScript(callable $callback): void
    {
        $pageNumber = 1;
        foreach ($this->_pages as $pid) {
            $this->_pdf->setPage($pid);
            $fontMetrics = $this->_dompdf->getFontMetrics();
            $callback($pageNumber, $this->get_page_count(), $this, $fontMetrics);
            $pageNumber++;
        }
    }
}

// Workaround for idiotic limitation on statics...
\Dompdf\Adapter\TCPDF::$PAPER_SIZES = \Dompdf\Adapter\CPDF::$PAPER_SIZES;

/**
 * Class added to access the protected methods/variables of TCPDF
 *
 * It assumes that $this->page does not change while an object is opened
 */
class My_TCPDF extends \TCPDF
{
    private $dompdf_num_objects = 0;
    private $dompdf_objects = [];

    private $dompdf_num_stack = 0;
    private $dompdf_stack = [];

    /**
     * Get page buffer
     */
    public function getPageBuffer($page) {
        return parent::getPageBuffer($page);
    }

    /**
     * Set page buffer
     */
    public function setPageBuffer($page, $data, $append = false)
    {
        parent::setPageBuffer($page, $data, $append);
    }

    /**
     * Save the TCPDF output buffer content in a stack and initialize it to an empty string
     * the function will return an object ID
     *
     * NOTE: can this method be called again without first issuing a closeObject()?
     */
    public function openObject()
    {
        if ($this->state == 2) {
            $curr_buffer = $this->getPageBuffer($this->page);
        } else {
            $curr_buffer = $this->getBuffer();
        }
        $this->dompdf_num_stack++;
        $this->dompdf_stack[$this->dompdf_num_stack] = ["c" => $curr_buffer, "p" => $this->page, "g" => $this->getGraphicVars()];

        if ($this->state == 2) {
            $this->setPageBuffer($this->page, "");
        } else {
            $this->buffer = "";
            $this->bufferlen = strlen($this->buffer);
        }
        $this->page = 1; // some output is not done if page = 0 (e.g SetDrawColor())
        $this->dompdf_num_objects++;
        $this->dompdf_objects[$this->dompdf_num_objects] = ["c" => "", "p" => 0, "g" => null];
        return $this->dompdf_num_objects;
    }

    /**
     * Restore the saved TCPDF output buffer content
     */
    public function closeObject()
    {
        if ($this->dompdf_num_stack > 0) {
            if ($this->state == 2) {
                $curr_buffer = $this->getPageBuffer($this->page);
            } else {
                $curr_buffer = $this->getBuffer();
            }
            $this->dompdf_objects[$this->dompdf_num_objects]["c"] = $curr_buffer;
            $this->dompdf_objects[$this->dompdf_num_objects]["p"] = $this->page;
            $this->dompdf_objects[$this->dompdf_num_objects]["g"] = $this->getGraphicVars();

            $saved_stack = $this->dompdf_stack[$this->dompdf_num_stack];

            if ($this->state == 2) {
                $this->setPageBuffer($this->page, $saved_stack["c"]);
            } else {
                $this->buffer = $saved_stack["c"];
                $this->bufferlen = strlen($this->buffer);
            }
            $this->page = $saved_stack["p"];
            $this->setGraphicVars($saved_stack["g"]);
            unset($this->dompdf_stack[$this->dompdf_num_stack]);
            $this->dompdf_num_stack--;
        }
    }

    /**
     * Reopen an existing object for editing
     *
     * Save the TCPDF output buffer content in the stack and initialize it with the contents of the object being reopened
     */
    public function reopenObject($id)
    {
        if ($this->state == 2) {
            $curr_buffer = $this->getPageBuffer($this->page);
        } else {
            $curr_buffer = $this->getBuffer();
        }
        $this->dompdf_num_stack++;
        $this->dompdf_stack[$this->dompdf_num_stack] = ["c" => $curr_buffer, "p" => $this->page, "g" => $this->getGraphicVars()];
        if ($this->state == 2) {
            $this->setPageBuffer($this->page, $this->dompdf_objects[$id]["c"]);
        } else {
            $this->buffer = $this->dompdf_objects[$id]["c"];
            $this->bufferlen = strlen($this->buffer);
        }
        $this->page = 1;
        //$this->page = $this->dompdf_objects[$id]["p"];
        $this->setGraphicVars($this->dompdf_objects[$id]["g"]);
    }

    /**
     * Get object
     */
    public function getObject($id)
    {
        return $this->dompdf_objects[$id];
    }
}
