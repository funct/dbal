<?php
/*
 *  $Id$
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM\Tools;

use Doctrine\ORM\Mapping\ClassMetadataInfo,
    Doctrine\ORM\Tools\Export\Driver\AbstractExporter,
    Doctrine\Common\Util\Inflector;

if ( ! class_exists('sfYaml', false)) {
    require_once __DIR__ . '/../../../vendor/sfYaml/sfYaml.class.php';
    require_once __DIR__ . '/../../../vendor/sfYaml/sfYamlDumper.class.php';
    require_once __DIR__ . '/../../../vendor/sfYaml/sfYamlInline.class.php';
    require_once __DIR__ . '/../../../vendor/sfYaml/sfYamlParser.class.php';
}

/**
 * Class to help with converting Doctrine 1 schema files to Doctrine 2 mapping files
 *
 * @license http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link    www.doctrine-project.org
 * @since   2.0
 * @version $Revision$
 * @author  Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author  Jonathan Wage <jonwage@gmail.com>
 * @author  Roman Borschel <roman@code-factory.org>
 */
class ConvertDoctrine1Schema
{
    /**
     * Constructor passes the directory or array of directories
     * to convert the Doctrine 1 schema files from
     *
     * @param string $from 
     * @author Jonathan Wage
     */
    public function __construct($from)
    {
        $this->_from = (array) $from;
    }

    /**
     * Get an array of ClassMetadataInfo instances from the passed
     * Doctrine 1 schema
     *
     * @return array $metadatas  An array of ClassMetadataInfo instances
     */
    public function getMetadatasFromSchema()
    {
        $schema = array();
        foreach ($this->_from as $path) {
            if (is_dir($path)) {
                $files = glob($path . '/*.yml');
                foreach ($files as $file) {
                    $schema = array_merge($schema, (array) \sfYaml::load($file));
                }
            } else {
                $schema = array_merge($schema, (array) \sfYaml::load($path));
            }
        }

        $metadatas = array();
        foreach ($schema as $name => $model) {
            $metadatas[] = $this->_convertToClassMetadataInfo($name, $model);
        }

        return $metadatas;
    }

    private function _convertToClassMetadataInfo($modelName, $model)
    {
        $metadata = new ClassMetadataInfo($modelName);

        $this->_convertTableName($modelName, $model, $metadata);
        $this->_convertColumns($modelName, $model, $metadata);
        $this->_convertIndexes($modelName, $model, $metadata);
        $this->_convertRelations($modelName, $model, $metadata);

        return $metadata;
    }

    private function _convertTableName($modelName, array $model, ClassMetadataInfo $metadata)
    {
        if (isset($model['tableName']) && $model['tableName']) {
            $e = explode('.', $model['tableName']);
            if (count($e) > 1) {
                $metadata->primaryTable['schema'] = $e[0];
                $metadata->primaryTable['name'] = $e[1];
            } else {
                $metadata->primaryTable['name'] = $e[0];
            }
        }
    }

    private function _convertColumns($modelName, array $model, ClassMetadataInfo $metadata)
    {
        $id = false;

        if (isset($model['columns']) && $model['columns']) {
            foreach ($model['columns'] as $name => $column) {
                $fieldMapping = $this->_convertColumn($modelName, $name, $column, $metadata);

                if (isset($fieldMapping['id']) && $fieldMapping['id']) {
                    $id = true;
                }
            }
        }

        if ( ! $id) {
            $fieldMapping = array(
                'fieldName' => 'id',
                'columnName' => 'id',
                'type' => 'integer',
                'id' => true
            );
            $metadata->mapField($fieldMapping);
            $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_AUTO);
        }
    }

    private function _convertColumn($modelName, $name, $column, ClassMetadataInfo $metadata)
    {
        if (is_string($column)) {
            $string = $column;
            $column = array();
            $column['type'] = $string;
        }
        if (preg_match("/([a-zA-Z]+)\(([0-9]+)\)/", $column['type'], $matches)) {
            $column['type'] = $matches[1];
            $column['length'] = $matches[2];
        }
        if ( ! isset($column['name'])) {
            $column['name'] = $name;
        }
        $fieldMapping = array();
        if (isset($column['primary'])) {
            $fieldMapping['id'] = true;
        }
        $fieldMapping['fieldName'] = isset($column['alias']) ? $column['alias'] : $name;
        $fieldMapping['columnName'] = $column['name'];
        $fieldMapping['type'] = $column['type'];
        if (isset($column['length'])) {
            $fieldMapping['length'] = $column['length'];
        }
        $allowed = array('precision', 'scale', 'unique', 'options', 'notnull', 'version');
        foreach ($column as $key => $value) {
            if (in_array($key, $allowed)) {
                $fieldMapping[$key] = $value;
            }
        }

        $metadata->mapField($fieldMapping);

        if (isset($column['autoincrement'])) {
            $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_AUTO);
        } else if (isset($column['sequence'])) {
            $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_SEQUENCE);
            $metadata->setSequenceGeneratorDefinition($definition);
            $definition = array(
                'sequenceName' => is_array($column['sequence']) ? $column['sequence']['name']:$column['sequence']
            );
            if (isset($column['sequence']['size'])) {
                $definition['allocationSize'] = $column['sequence']['size'];
            }
            if (isset($column['sequence']['value'])) {
                $definition['initialValue'] = $column['sequence']['value'];
            }
        }
        return $fieldMapping;
    }

    private function _convertIndexes($modelName, array $model, ClassMetadataInfo $metadata)
    {
        if (isset($model['indexes']) && $model['indexes']) {
            foreach ($model['indexes'] as $name => $index) {
                $metadata->primaryTable['indexes'][$name] = array(
                    'columns' => $index['fields']
                );

                if (isset($index['type']) && $index['type'] == 'unique') {
                    $metadata->primaryTable['uniqueConstraints'][] = $index['fields'];
                }
            }
        }
    }

    private function _convertRelations($modelName, array $model, ClassMetadataInfo $metadata)
    {
        if (isset($model['relations']) && $model['relations']) {
            foreach ($model['relations'] as $name => $relation) {
                if ( ! isset($relation['alias'])) {
                    $relation['alias'] = $name;
                }
                if ( ! isset($relation['class'])) {
                    $relation['class'] = $name;
                }
                if ( ! isset($relation['local'])) {
                    $relation['local'] = Inflector::tableize($relation['class']);
                }
                if ( ! isset($relation['foreign'])) {
                    $relation['foreign'] = 'id';
                }
                if ( ! isset($relation['foreignAlias'])) {
                    $relation['foreignAlias'] = $modelName;
                }

                if (isset($relation['refClass'])) {
                    $type = 'many';
                    $foreignType = 'many';
                } else {
                    $type = isset($relation['type']) ? $relation['type'] : 'one';
                    $foreignType = isset($relation['foreignType']) ? $relation['foreignType'] : 'many';
                    $joinColumns = array(
                        array(
                            'name' => $relation['local'],
                            'referencedColumnName' => $relation['foreign'],
                            'onDelete' => isset($relation['onDelete']) ? $relation['onDelete'] : null,
                            'onUpdate' => isset($relation['onUpdate']) ? $relation['onUpdate'] : null,
                        )
                    );
                }

                if ($type == 'one' && $foreignType == 'one') {
                    $method = 'mapOneToOne';
                } else if ($type == 'many' && $foreignType == 'many') {
                    $method = 'mapManyToMany';
                } else {
                    $method = 'mapOneToMany';
                }

                $associationMapping = array();
                $associationMapping['fieldName'] = $relation['alias'];
                $associationMapping['targetEntity'] = $relation['class'];
                $associationMapping['mappedBy'] = $relation['foreignAlias'];
                $associationMapping['joinColumns'] = $joinColumns;

                $metadata->$method($associationMapping);
            }
        }
    }
}