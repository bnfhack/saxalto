<?xml version="1.0" encoding="UTF-8"?>
<xsl:transform version="1.1"
  xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
  xmlns:alto="http://bibnum.bnf.fr/ns/alto_prod"
  xmlns="http://www.tei-c.org/ns/1.0"
  xmlns:tei="http://www.tei-c.org/ns/1.0"
  exclude-result-prefixes="alto tei"
  >
  <xsl:output method="xml" indent="yes" encoding="UTF-8"/>
  <xsl:variable name="gallica" select="/tei:TEI/tei:teiHeader/tei:fileDesc/tei:sourceDesc/*/tei:idno"/>
  <xsl:variable name="lf" select="'&#10;'"/>
  <xsl:variable name="num">0123456789</xsl:variable>
  <!-- Majuscules, pour conversions. -->
  <xsl:variable name="caps">ABCDEFGHIJKLMNOPQRSTUVWXYZÆŒÇÀÁÂÃÄÅÈÉÊËÌÍÎÏÒÓÔÕÖÙÚÛÜÝ</xsl:variable>
  <!-- Minuscules, pour conversions -->
  <xsl:variable name="mins">abcdefghijklmnopqrstuvwxyzæœçàáâãäåèéêëìíîïòóôõöùúûüý</xsl:variable>
  <xsl:template match="/">
    <!-- Inutile de valider encore
    <xsl:processing-instruction name="xml-model">href="http://svn.code.sf.net/p/algone/code/teibook/teibook.rng" type="application/xml"  schematypens="http://relaxng.org/ns/structure/1.0"</xsl:processing-instruction>
    -->
    <xsl:apply-templates/>
  </xsl:template>
  <xsl:template match="node() | @*">
    <xsl:copy>
      <xsl:apply-templates select="node() | @*"/>
    </xsl:copy>
  </xsl:template>
  <xsl:template match="tei:body">
    <xsl:copy>
      <xsl:copy-of select="@*"/>
      <xsl:apply-templates select="*"/>
      <xsl:call-template name="divClose">
        <xsl:with-param name="n" select=".//tei:head[position() = last()]/@n"/>
      </xsl:call-template>
    </xsl:copy>
  </xsl:template>
  <xsl:template match="tei:book">
    <xsl:apply-templates/>
  </xsl:template>
  <xsl:template match="tei:page">
    <pb>
      <xsl:copy-of select="@xml:id|@xml:type"/>
      <xsl:attribute name="n">
        <xsl:value-of select="tei:fw"/>
      </xsl:attribute>
      <xsl:variable name="f" select="number( substring-after(@xml:id, 'PAG_') )"/>
      <xsl:if test="$gallica and $f &gt; 0">
        <xsl:attribute name="corresp">
          <xsl:value-of select="$gallica"/>
          <xsl:text>/f</xsl:text>
          <xsl:value-of select="$f"/>
          <xsl:text>.image</xsl:text>
        </xsl:attribute>
      </xsl:if>
    </pb>
    <xsl:apply-templates/>
  </xsl:template>
  <xsl:template name="divClose">
    <xsl:param name="n"/>
    <xsl:choose>
      <xsl:when test="$n &gt; 0">
        <xsl:processing-instruction name="div">/</xsl:processing-instruction>
        <xsl:call-template name="divClose">
          <xsl:with-param name="n" select="$n - 1"/>
        </xsl:call-template>
      </xsl:when>     
    </xsl:choose>
  </xsl:template>
  <xsl:template name="divOpen">
    <xsl:param name="n"/>
    <xsl:choose>
      <xsl:when test="$n &gt; 0">
        <xsl:processing-instruction name="div"/>
        <xsl:call-template name="divOpen">
          <xsl:with-param name="n" select="$n - 1"/>
        </xsl:call-template>
      </xsl:when>
    </xsl:choose>
  </xsl:template>
  <!-- TODO, get prev <pb> -->
  <xsl:template match="tei:head">
    <xsl:variable name="level" select="@n"/>
    <xsl:variable name="prev" select="preceding::tei:head[1]/@n"/>
    <xsl:choose>
      <xsl:when test="$prev">
        <xsl:call-template name="divClose">
          <xsl:with-param name="n" select="1+ $prev - $level"/>
        </xsl:call-template>
      </xsl:when>
      <!-- no prev, no close
        <xsl:otherwise>
          <xsl:call-template name="divClose">
            <xsl:with-param name="n" select="1"/>
          </xsl:call-template>
        </xsl:otherwise>
        -->
    </xsl:choose>
    <!-- Always one -->
    <xsl:call-template name="divOpen">
      <xsl:with-param name="n" select="1"/>
    </xsl:call-template>
    <!-- Sometimes more  -->
    <xsl:variable name="open">
      <xsl:choose>
        <xsl:when test="$prev">
          <xsl:value-of select="$level - $prev - 1"/>
        </xsl:when>
        <xsl:otherwise>
          <xsl:value-of select="$level - 1"/>
        </xsl:otherwise>
      </xsl:choose>
    </xsl:variable>
    <xsl:call-template name="divOpen">
      <xsl:with-param name="n" select="$open"/>
    </xsl:call-template>
    <xsl:copy>
      <!--
      <xsl:copy-of select="@n"/>
      -->
      <xsl:apply-templates/>
    </xsl:copy>
  </xsl:template>
  <xsl:template match="tei:b | tei:i | tei:sub | tei:u ">
    <hi rend="{local-name()}">
      <xsl:apply-templates/>
    </hi>
  </xsl:template>
  <xsl:template match="tei:sup">
    <xsl:variable name="text" select="."/>
    <xsl:choose>
      <xsl:when test="translate(., $num, '') != ''">
        <hi rend="sup">
          <xsl:apply-templates/>
        </hi>
      </xsl:when>
      <!-- Tentative de raccrochage de note, ratée
      <xsl:when test="ancestor::tei:page/*[starts-with(normalize-space(.), $text)][*[1][local-name()='small']]">
        <note>
          <xsl:attribute name="xml:id">
            <xsl:value-of select="ancestor::tei:page/@xml:id"/>
            <xsl:text>-note</xsl:text>
            <xsl:value-of select="$text"/>
          </xsl:attribute>
          <xsl:value-of select="$lf"/>
          <xsl:apply-templates select="ancestor::tei:page/*[starts-with(normalize-space(.), $text)][*[1][local-name()='small']]/*/node()"/>
          <xsl:value-of select="$lf"/>
        </note>
      </xsl:when>
      -->
      <xsl:otherwise>
        <hi rend="sup">
          <xsl:apply-templates/>
        </hi>
      </xsl:otherwise>
    </xsl:choose>
  </xsl:template>
  <!-- 
    Attention aux blocs qui raccrochent par erreur une ligne <small>
    
    *[tei:small and not(*[local-name()!='small'])]
    -->
  <xsl:template match="*[tei:small and not(*[local-name()!='small'])]">
    <xsl:copy>
      <xsl:copy-of select="@*"/>
      <xsl:attribute name="rend">
        <xsl:value-of select="normalize-space(concat(@rend, ' ', 'small'))"/>
      </xsl:attribute>
      <xsl:apply-templates select="text()|*/node()"/>
    </xsl:copy>
  </xsl:template>
  <xsl:template match="tei:fw"/>
  <xsl:template match="tei:head[@n='1']/text()">
    <xsl:value-of select="substring(., 1 , 1)"/>
    <xsl:value-of select="translate(substring(., 2), $caps, $mins)"/>
  </xsl:template>
  <!-- Pour débogage afficher un path -->
  <xsl:template name="idpath">
    <xsl:for-each select="ancestor-or-self::*">
      <xsl:text>/</xsl:text>
      <xsl:value-of select="name()"/>
      <xsl:if test="count(../*[name()=name(current())]) &gt; 1">
        <xsl:text>[</xsl:text>
        <xsl:number/>
        <xsl:text>]</xsl:text>
      </xsl:if>
    </xsl:for-each>
  </xsl:template>
  
</xsl:transform>