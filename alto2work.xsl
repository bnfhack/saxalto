<?xml version="1.0" encoding="UTF-8"?>
<xsl:transform version="1.1"
  xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
  xmlns:alto="http://bibnum.bnf.fr/ns/alto_prod"
  xmlns="http://www.tei-c.org/ns/1.0"
  exclude-result-prefixes="alto"
  >
  <xsl:key name="style" match="alto:TextStyle | alto:ParagraphStyle" use="@ID"/>
  <xsl:output method="xml" indent="no" encoding="UTF-8" omit-xml-declaration="yes"/>
  <xsl:variable name="lf" select="'&#10;'"/>
  <xsl:template match="alto:Page">
    <page>
      <xsl:attribute name="xml:id">
        <xsl:value-of select="@ID"/>
      </xsl:attribute>
      <xsl:if test="@PAGECLASS">
        <xsl:attribute name="type">
          <xsl:value-of select="@PAGECLASS"/>
        </xsl:attribute>
      </xsl:if>
      <xsl:apply-templates select="*"/>
      <xsl:value-of select="$lf"/>
    </page>    
  </xsl:template>
  <xsl:template match="*">
    <xsl:apply-templates select="*"/>
  </xsl:template>
  <xsl:template match="alto:TextBlock">
      <xsl:variable name="style">
        <xsl:call-template name="style"/>
      </xsl:variable>
      <!-- ? @FIRSTLINE @LINESPACE @RIGHT @LEFT -->
      <xsl:choose>
        <!--
        <xsl:when test="./*[1]/alto:String[1]/@CONTENT='&#x2014;'">
          <xsl:value-of select="$lf"/>
          <item>
            <xsl:apply-templates select="*"/>
            <xsl:value-of select="$lf"/>
          </item>
        </xsl:when>
        -->
        <xsl:when test="contains($style, 'Block')">
          <xsl:value-of select="$lf"/>
          <p>
            <xsl:apply-templates select="*"/>
            <xsl:value-of select="$lf"/>
          </p>
        </xsl:when>
        <xsl:when test="contains($style, 'Left')">
          <xsl:value-of select="$lf"/>
          <p rend="left">
            <xsl:apply-templates select="*"/>
            <xsl:value-of select="$lf"/>
          </p>
        </xsl:when>
        <xsl:when test="contains($style, 'Center')">
          <xsl:value-of select="$lf"/>
          <p rend="center">
            <xsl:apply-templates select="*"/>
            <xsl:value-of select="$lf"/>
          </p>
        </xsl:when>
        <xsl:otherwise>
          <xsl:value-of select="$lf"/>
          <p>
            <xsl:apply-templates select="*"/>
            <xsl:value-of select="$lf"/>
          </p>
        </xsl:otherwise>
      </xsl:choose>
  </xsl:template>
  <xsl:template match="alto:TextLine">
    <xsl:variable name="style">
      <xsl:call-template name="style"/>
      <xsl:text> </xsl:text>
    </xsl:variable>
    <xsl:value-of select="$lf"/>
    <xsl:variable name="size" select="normalize-space(substring-before(substring-after($style, 'size'), ' '))"/>
    <xsl:variable name="span">
      <xsl:choose>
        <!-- pb sur de l’italique globale avec de l’italique locale -->
        <xsl:when test="false() and contains($style, 'italics')">
          <i>
            <xsl:apply-templates select="*"/>
          </i>
        </xsl:when>
        <xsl:otherwise>
          <xsl:apply-templates select="*"/>
        </xsl:otherwise>
      </xsl:choose>
    </xsl:variable>
    <xsl:choose>
      <!-- pas pertinent ici
      <xsl:when test="contains($style, 'COURIER NEW')">
        <tt>
          <xsl:apply-templates select="*"/>
        </tt>
      </xsl:when>
      -->
      <xsl:when test="$size &lt; 10">
        <small>
          <xsl:copy-of select="$span"/>
        </small>
      </xsl:when>
      <xsl:when test="$size &gt; 12">
        <big>
          <xsl:copy-of select="$span"/>
        </big>
      </xsl:when>
      <xsl:otherwise>
        <xsl:copy-of select="$span"/>
      </xsl:otherwise>
    </xsl:choose>
  </xsl:template>
  <xsl:template name="style">
    <xsl:param name="name" select="normalize-space(@STYLEREFS)"/>
    <xsl:choose>
      <xsl:when test="normalize-space($name) = ''"/>
      <xsl:when test="contains($name, ' ')">
        <xsl:call-template name="style">
          <xsl:with-param name="name" select="substring-before($name, ' ')"/>
        </xsl:call-template>
        <xsl:call-template name="style">
          <xsl:with-param name="name" select="substring-after($name, ' ')"/>
        </xsl:call-template>
      </xsl:when>
      <xsl:otherwise>
        <xsl:variable name="style" select="key('style', $name)"/>
        <xsl:text> </xsl:text>
        <xsl:value-of select="$style/@FONTSTYLE"/>
        <xsl:text> </xsl:text>
        <xsl:value-of select="$style/@FONTFAMILY"/>
        <xsl:if test="$style/@FONTSIZE">
          <xsl:text> size</xsl:text>
          <xsl:value-of select="$style/@FONTSIZE"/>
        </xsl:if>
        <xsl:if test="$style/@FIRSTLINE">
          <xsl:text> indent</xsl:text>
          <xsl:value-of select="$style/@FIRSTLINE"/>
        </xsl:if>
        <xsl:text> </xsl:text>
        <xsl:value-of select="$style/@ALIGN"/>
        <xsl:text> </xsl:text>
      </xsl:otherwise>
    </xsl:choose>
  </xsl:template>
  <xsl:template match="alto:String">
    <xsl:variable name="style">
      <xsl:call-template name="style"/>
    </xsl:variable>
    <!-- Contenu -->
    <xsl:variable name="text">
      <xsl:choose>
        <!-- Césure, erreur parfois sur les notes de bas de page, tester sur small -->
        <!-- Ne pas raccrocher un chiffre à un bout de mot (souvent appel de note
je deman-
1. Note…
        -->
        <!-- 2e partie d’une césure -->
        <xsl:when test="@SUBS_TYPE='HypPart2'"/>
        <xsl:when test="@SUBS_CONTENT">
          <xsl:value-of select="@SUBS_CONTENT"/>
        </xsl:when>
        <xsl:otherwise>
          <xsl:value-of select="@CONTENT"/>
        </xsl:otherwise>
      </xsl:choose>
    </xsl:variable>
    <xsl:choose>
      <xsl:when test="$text=''"/>
      <!-- Ne pas répliquer une info de style du parent ? Attention à italique globale/locale -->
      <!--
      <xsl:when test="@STYLEREFS=../@STYLEREFS">
        <xsl:value-of select="$text"/>
      </xsl:when>
      -->
      <xsl:when test="contains($style, 'superscript')">
        <sup>
          <xsl:value-of select="$text"/>
        </sup>
      </xsl:when>
      <xsl:when test="contains($style,'subscript')">
        <sub>
          <xsl:value-of select="$text"/>
        </sub>
      </xsl:when>
      <xsl:when test="contains($style,'smallcaps')">
        <sc>
          <xsl:value-of select="$text"/>
        </sc>
      </xsl:when>
      <xsl:when test="contains($style,'underline') and contains($style,'italics')">
        <u>
          <i>
            <xsl:value-of select="$text"/>
          </i>
        </u>
      </xsl:when>
      <xsl:when test="contains($style,'italics')">
        <i>
          <xsl:value-of select="$text"/>
        </i>
      </xsl:when>
      <xsl:when test="contains($style,'underline')">
        <u>
          <xsl:value-of select="$text"/>
        </u>
      </xsl:when>
      <!--
      <xsl:when test="contains($style,'COURIER NEW')">
        <tt>
          <xsl:value-of select="$text"/>
        </tt>
      </xsl:when>
      -->
      <xsl:otherwise>
        <xsl:value-of select="$text"/>
      </xsl:otherwise>
    </xsl:choose>
  </xsl:template>

  <xsl:template match="alto:SP">
    <xsl:text> </xsl:text>
  </xsl:template>
</xsl:transform>