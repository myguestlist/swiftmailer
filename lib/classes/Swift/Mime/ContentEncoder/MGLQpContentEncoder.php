<?php

/*
 * This file is part of MyGuestlist for Swiftmailer.
 * (c) 2008 MyGuestlist
 *
 */

/**
 * Handles Quoted Printable (QP) Transfer Encoding in Swift Mailer using the qprint command line application.
 *
 * @package    Swift
 * @subpackage Mime
 * @author     Alex Ilievski
 */
class Swift_Mime_ContentEncoder_MGLQpContentEncoder implements Swift_Mime_ContentEncoder
{
    /**
     * @var null|string
     */
    private $charset;
    private $descriptorspec;

    /**
     * @param null|string $charset
     */
    public function __construct($charset = null)
    {
        $this->charset = $charset ? $charset : 'iso-8859-1';
        
        $this->descriptorspec = array(
           0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
           1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
           2 => array("file", "/tmp/error-output.txt", "a") // stderr is a file to write to
        );
    }

    /**
     * Notify this observer that the entity's charset has changed.
     *
     * @param string $charset
     */
    public function charsetChanged($charset)
    {
        $this->charset = $charset;
    }

    /**
     * Encode $in to $out.
     *
     * @param Swift_OutputByteStream $os              to read from
     * @param Swift_InputByteStream  $is              to write to
     * @param integer                $firstLineOffset
     * @param integer                $maxLineLength   0 indicates the default length for this encoding
     *
     * @throws RuntimeException
     */
    public function encodeByteStream(Swift_OutputByteStream $os, Swift_InputByteStream $is, $firstLineOffset = 0, $maxLineLength = 0)
    {
        $string = '';

        while (false !== $bytes = $os->read(8192)) {
            $string .= $bytes;
        }

        $is->write($this->encodeString($string));
    }

    /**
     * Get the MIME name of this content encoding scheme.
     *
     * @return string
     */
    public function getName()
    {
        return 'quoted-printable';
    }

    /**
     * Encode a given string to produce an encoded string.
     *
     * @param string  $string
     * @param integer $firstLineOffset if first line needs to be shorter
     * @param integer $maxLineLength   0 indicates the default length for this encoding
     *
     * @return string
     *
     * @throws RuntimeException
     */
    public function encodeString($string, $firstLineOffset = 0, $maxLineLength = 0)
    {
        $qpstring = '';

        // Write email to temp file to be passed into print function
        $tmp_file = "/tmp/" . uniqid('qp', true);
        file_put_contents($tmp_file, $this->_standardize($string)); 

        // Execute command line qprint and get output
        $qpstring = shell_exec("/usr/local/bin/qprint -e {$tmp_file} 2> /dev/null");

        unlink($tmp_file);

        // Check the output is OK. Otherwise default to PHP quoted printable function
        if (empty($qpstring))
        {
            $qpstring = $this->_quoted_printable_encode($this->_standardize($string));
        }

        return $qpstring;
    }

    /**
     * Make sure CRLF is correct and HT/SPACE are in valid places.
     *
     * @param string $string
     *
     * @return string
     */
    protected function _standardize($string)
    {
        // transform CR or LF to CRLF
        $string = preg_replace('~=0D(?!=0A)|(?<!=0D)=0A~', '=0D=0A', $string);
        // transform =0D=0A to CRLF
        $string = str_replace(array("\t=0D=0A", " =0D=0A", "=0D=0A"), array("=09\r\n", "=20\r\n", "\r\n"), $string);

        switch ($end = ord(substr($string, -1))) {
            case 0x09:
                $string = substr_replace($string, '=09', -1);
                break;
            case 0x20:
                $string = substr_replace($string, '=20', -1);
                break;
        }

        return $string;
    }
    
    protected function _quoted_printable_encode($input, $line_max = 75)
    {
        $hex = array('0','1','2','3','4','5','6','7',
                '8','9','A','B','C','D','E','F');
        $lines = preg_split("/(?:\r\n|\r|\n)/", $input);
        //$linebreak = "=0D=0A=\r\n";
        $linebreak = "=\r\n";
        /* the linebreak also counts as characters in the mime_qp_long_line
         * rule of spam-assassin */
        $line_max = $line_max - strlen($linebreak);
        $escape = "=";
        $output = "";
        $cur_conv_line = "";
        $length = 0;
        $whitespace_pos = 0;
        $addtl_chars = 0;

        // iterate lines
        for ($j = 0; $j < count($lines); $j++)
        {
            $line = $lines[$j];
            $linlen = strlen($line);

            // iterate chars
            for ($i = 0; $i < $linlen; $i++)
            {
                $c = substr($line, $i, 1);
                $dec = ord($c);
                $length++;

                if ($dec == 32)
                {
                    // space occurring at end of line, need to encode
                    if (($i == ($linlen - 1)))
                    {
                        $c = "=20";
                        $length += 2;
                    }

                    $addtl_chars = 0;
                    $whitespace_pos = $i;
                }
                elseif (($dec == 61) || ($dec < 32 ) || ($dec > 126))
                {
                    $h2 = floor($dec/16); $h1 = floor($dec%16);
                    $c = $escape . $hex["$h2"] . $hex["$h1"];
                    $length += 2;
                    $addtl_chars += 2;
                }

                // length for wordwrap exceeded, get a newline into the text
                if ($length >= $line_max)
                {
                    $cur_conv_line .= $c;

                    // read only up to the whitespace for the current line
                    $whitesp_diff = $i - $whitespace_pos + $addtl_chars;

                    /* the text after the whitespace will have to be read
                     * again ( + any additional characters that came into
                     * existence as a result of the encoding process after the whitespace)
                     *
                     * Also, do not start at 0, if there was *no* whitespace in
                     * the whole line */
                    if (($i + $addtl_chars) > $whitesp_diff)
                    {
                        $output .= substr($cur_conv_line, 0, (strlen($cur_conv_line) - $whitesp_diff)) . $linebreak;
                        $i =  $i - $whitesp_diff + $addtl_chars;
                    }
                    else
                    {
                        $output .= $cur_conv_line . $linebreak;
                    }

                    $cur_conv_line = "";
                    $length = 0;
                    $whitespace_pos = 0;
                }
                else
                {
                    // length for wordwrap not reached, continue reading
                    $cur_conv_line .= $c;
                }
            } // end of for

            $length = 0;
            $whitespace_pos = 0;
            $output .= $cur_conv_line;
            $cur_conv_line = "";

            if ($j <= count($lines) - 1)
            {
                $output .= $linebreak;
            }
        } // end for

        return trim($output);
    } // end quoted_printable_encode 
}
