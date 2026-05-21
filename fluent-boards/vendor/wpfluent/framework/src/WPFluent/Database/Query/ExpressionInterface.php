<?php

namespace FluentBoards\Framework\Database\Query;

use FluentBoards\Framework\Database\BaseGrammar;

interface ExpressionInterface
{
    /**
     * Get the value of the expression.
     *
     * @param  \FluentBoards\Framework\Database\BaseGrammar $grammar
     * @return string|int|float
     */
    public function getValue(BaseGrammar $grammar);
}
